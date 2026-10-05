<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;

/**
 * A gift card product without an image of its own shows the plugin's generic
 * gift card rather than WooCommerce's grey placeholder.
 *
 * The picture is copied into the media library once and handed out as the
 * product's image id, so everything that shows a product image (the gallery,
 * the cart, the blocks, the emails) gets it in the sizes it asks for, with no
 * template to override. The product itself is not changed: set an image on
 * it and that one is used.
 */
class FallbackImage implements Module
{
    public const OPTION = 'wc_store_balance_fallback_image';

    public const FILTER = 'wc_store_balance_fallback_image_id';

    /** Stored instead of an id once a shop has deleted the picture: it is not wanted. */
    private const REMOVED = 'removed';

    private const FILE = 'assets/images/gift-card.png';

    public function register(): void
    {
        add_filter('woocommerce_product_get_image_id', [$this, 'imageId'], 20, 2);
        add_action('admin_init', [$this, 'ensure']);
        add_action('delete_attachment', [$this, 'removed']);
    }

    /**
     * @param  mixed  $imageId
     * @param  \WC_Product  $product
     * @return mixed
     */
    public function imageId($imageId, $product)
    {
        if ($imageId || ! GiftCardProduct::isGiftCard($product)) {
            return $imageId;
        }

        return self::id() ?: $imageId;
    }

    /**
     * The attachment to show, or 0 for none.
     */
    public static function id(): int
    {
        $stored = get_option(self::OPTION);
        $id = is_numeric($stored) && get_post_type((int) $stored) === 'attachment' ? (int) $stored : 0;

        /**
         * The image shown for a gift card product that has none. Return
         * another attachment id to use the shop's own, or 0 for no fallback.
         *
         * @param  int  $id
         */
        return max(0, (int) apply_filters(self::FILTER, $id));
    }

    /**
     * Copy the picture into the media library when it is not there yet. Done
     * from the admin only, so no shopper's request writes to the uploads
     * folder, and two of them cannot do it at once.
     */
    public function ensure(): void
    {
        $stored = get_option(self::OPTION);

        if ($stored === self::REMOVED || (is_numeric($stored) && get_post_type((int) $stored) === 'attachment')) {
            return;
        }

        if (wp_doing_ajax() || ! current_user_can('upload_files')) {
            return;
        }

        $id = self::create();

        if ($id) {
            update_option(self::OPTION, $id, false);
        }
    }

    /**
     * Deleting the picture from the media library is a decision: it is not
     * put back.
     *
     * @param  int  $attachmentId
     */
    public function removed($attachmentId): void
    {
        if ((int) get_option(self::OPTION) === (int) $attachmentId) {
            update_option(self::OPTION, self::REMOVED, false);
        }
    }

    public static function create(): int
    {
        $source = WC_STORE_BALANCE_PATH.'/'.self::FILE;
        $contents = is_readable($source) ? file_get_contents($source) : false;

        if ($contents === false) {
            Logger::error('The gift card fallback image could not be read.', ['file' => $source]);

            return 0;
        }

        $upload = wp_upload_bits('gift-card.png', null, $contents);

        if (! empty($upload['error'])) {
            Logger::error('The gift card fallback image could not be copied to the uploads folder.', ['error' => $upload['error']]);

            return 0;
        }

        $id = wp_insert_attachment([
            'post_title' => __('Gift card', 'wp-woocommerce-store-balance'),
            'post_mime_type' => 'image/png',
            'post_status' => 'inherit',
        ], $upload['file'], 0, true);

        if (is_wp_error($id)) {
            Logger::error('The gift card fallback image could not be added to the media library.', ['error' => $id->get_error_message()]);

            return 0;
        }

        require_once ABSPATH.'wp-admin/includes/image.php';

        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        update_post_meta($id, '_wp_attachment_image_alt', __('Gift card', 'wp-woocommerce-store-balance'));

        return (int) $id;
    }
}
