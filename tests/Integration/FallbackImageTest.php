<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Modules\FallbackImage;
use GeneroWP\StoreBalance\Plugin;
use WC_Product_Simple;

class FallbackImageTest extends TestCase
{
    protected function tearDown(): void
    {
        $id = get_option(FallbackImage::OPTION);

        if (is_numeric($id)) {
            wp_delete_attachment((int) $id, true);
        }

        delete_option(FallbackImage::OPTION);
        delete_transient(FallbackImage::OPTION.'_attempt');

        parent::tearDown();
    }

    protected function ensure(): int
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Plugin::getInstance()->module(FallbackImage::class)->ensure();

        return (int) get_option(FallbackImage::OPTION);
    }

    public function test_a_gift_card_without_an_image_shows_the_plugins_picture(): void
    {
        $id = $this->ensure();

        $this->assertSame('attachment', get_post_type($id));
        $this->assertFileExists((string) get_attached_file($id));
        $this->assertSame($id, (int) $this->giftCardProduct()->get_image_id());
    }

    public function test_the_picture_is_added_to_the_media_library_only_once(): void
    {
        $this->assertSame($this->ensure(), $this->ensure());
    }

    public function test_a_shopper_does_not_create_it(): void
    {
        wp_set_current_user(0);
        Plugin::getInstance()->module(FallbackImage::class)->ensure();

        $this->assertFalse(get_option(FallbackImage::OPTION));
        $this->assertSame('', (string) $this->giftCardProduct()->get_image_id());
    }

    public function test_the_products_own_image_wins(): void
    {
        $this->ensure();
        $own = self::factory()->attachment->create();
        $product = $this->giftCardProduct();
        $product->set_image_id($own);

        $this->assertSame($own, (int) $product->get_image_id());
    }

    public function test_other_products_are_left_alone(): void
    {
        $this->ensure();
        $product = new WC_Product_Simple;
        $product->set_name('Shoe');
        $product->save();

        $this->assertSame('', (string) $product->get_image_id());
    }

    public function test_the_product_itself_is_not_changed(): void
    {
        $this->ensure();

        $this->assertSame('', (string) $this->giftCardProduct()->get_image_id('edit'));
    }

    public function test_once_deleted_from_the_media_library_it_stays_away(): void
    {
        wp_delete_attachment($this->ensure(), true);
        $this->ensure();

        $this->assertSame('removed', get_option(FallbackImage::OPTION));
        $this->assertSame('', (string) $this->giftCardProduct()->get_image_id());
    }

    public function test_a_shop_can_use_its_own_or_none(): void
    {
        $this->ensure();
        $own = self::factory()->attachment->create();

        $filter = static fn () => $own;
        add_filter(FallbackImage::FILTER, $filter);
        $this->assertSame($own, (int) $this->giftCardProduct()->get_image_id());
        remove_filter(FallbackImage::FILTER, $filter);

        add_filter(FallbackImage::FILTER, '__return_zero');
        $this->assertSame('', (string) $this->giftCardProduct()->get_image_id());
        remove_filter(FallbackImage::FILTER, '__return_zero');
    }
}
