<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use WC_Session;

/**
 * A WooCommerce session that lives in memory.
 *
 * The real handler sets cookies and writes to the sessions table on shutdown,
 * neither of which means anything on the command line. What the plugin needs
 * from a session — somewhere to keep the applied codes and the "use my
 * balance" choice — is all here.
 */
class MemorySession extends WC_Session
{
    public function init(): void
    {
        $this->_customer_id = is_user_logged_in()
            ? (string) get_current_user_id()
            : 't_'.substr(md5(uniqid('', true)), 0, 30);
    }

    public function has_session(): bool
    {
        return true;
    }

    /**
     * @param  bool  $set
     */
    public function set_customer_session_cookie($set): void {}

    public function forget_session(): void
    {
        $this->_data = [];
    }

    public function destroy_session(): void
    {
        $this->_data = [];
    }

    public function save_data(): void {}
}
