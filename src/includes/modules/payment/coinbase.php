<?php

require_once __DIR__ . '/coinbase/init.php';
require_once __DIR__ . '/coinbase/const.php';

class coinbase
{
    public $code;
    public $title;
    public $description;
    public $enabled;
    public $order_status;
    private $_check;

    function __construct()
    {
        global $order;

        $this->code = 'coinbase';
        $this->title = MODULE_PAYMENT_COINBASE_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_COINBASE_TEXT_DESCRIPTION;
        $this->sort_order = MODULE_PAYMENT_COINBASE_SORT_ORDER;
        $this->enabled = ((MODULE_PAYMENT_COINBASE_STATUS == 'True') ? true : false);

        if ((int)MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID;
        }
    }

    function javascript_validation()
    {
        return false;
    }

    function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->title
        );
    }

    function pre_confirmation_check()
    {
        return false;
    }

    function confirmation()
    {
        return false;
    }

    function process_button()
    {
        return false;
    }

    function get_error()
    {
        return false;
    }

    /**
     * Check to see whether module is installed
     *
     * @return boolean
     */
    function check()
    {
        if (!isset($this->_check)) {
            $check_query = tep_db_query("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_COINBASE_STATUS'");
            $this->_check = tep_db_num_rows($check_query) > 0;
        }
        return $this->_check;
    }

    /**
     * Store transaction info to the order and process any results that come back from the payment gateway
     */
    function before_process()
    {
        return false;
    }

    public function after_process()
    {
        global $insert_id, $order, $products;

        $sql_data_array = array(
            'orders_id' => $insert_id,
            'orders_status_id' => $this->order_status,
            'date_added' => 'now()',
            'customer_notified' => 0
        );

        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $products = $this->getOrderProducts($order);

        $chargeData = array(
            'local_price' => array(
                'amount' => $order->info['total'],
                'currency' => $order->info['currency'],
            ),
            'pricing_type' => 'fixed_price',
            'name' => STORE_NAME . ' order #' . $insert_id,
            'description' => mb_substr(join($products, ', '), 0, 200),
            'metadata' => [
                METADATA_SOURCE_PARAM => METADATA_SOURCE_VALUE,
                METADATA_INVOICE_PARAM => $insert_id,
                METADATA_CLIENT_PARAM => $_SESSION['customer_id'],
                'email' =>  $order->customer['email_address'],
                'first_name' => $order->delivery['firstname'] != '' ? $order->delivery['firstname'] : $order->billing['firstname'],
                'last_name' => $order->delivery['lastname'] != '' ? $order->delivery['lastname'] : $order->billing['lastname'],
            ],
            'redirect_url' => tep_href_link(FILENAME_CHECKOUT_SUCCESS, 'checkout_id=' . $insert_id, 'SSL'),
            'cancel_url' => tep_href_link(FILENAME_CHECKOUT_PAYMENT)
        );

        \Coinbase\ApiClient::init(MODULE_PAYMENT_COINBASE_API_KEY);

        try {
            $chargeObj = \Coinbase\Resources\Charge::create($chargeData);
        } catch (\Exception $exception) {
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . $exception->getMessage(), 'SSL', true));
        }

        $_SESSION['cart']->reset(true);
        tep_redirect($chargeObj->hosted_url);

        return false;
    }

    private function getOrderProducts($order) {

        foreach ($order->products as $product) {
            $result[] = $product['qty'] . ' × ' . $product['name'];
        }

        return $result;
    }

    /**
     * Used to display error message details
     *
     * @return boolean
     */
    function output_error() {
        return false;
    }

    function install()
    {
        global $messageStack;

        if (defined('MODULE_PAYMENT_COINBASE_STATUS')) {
            $messageStack->add_session('Coinbase Commerce module already installed.', 'error');
            tep_redirect(tep_href_link(FILENAME_MODULES, 'set=payment&module=coinbase', 'NONSSL'));
            return 'failed';
        }

        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Coinbase Commerce Module', 'MODULE_PAYMENT_COINBASE_STATUS', 'True', 'Do you want to accept CoinBase Commerce payments?', '6', '1', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('API Key', 'MODULE_PAYMENT_COINBASE_API_KEY','', 'Get API Key from Coinbase Commerce Dashboard <a href=\"https://commerce.coinbase.com/dashboard/settings\" target=\"_blank\">Settings &gt; API keys &gt; Create an API key</a>', '6', '2', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Shared Secret Key', 'MODULE_PAYMENT_COINBASE_SHARED_SECRET','', 'Get Shared Secret Key from Coinbase Commerce Dashboard <a href=\"https://commerce.coinbase.com/dashboard/settings\" target=\"_blank\">Settings &gt; Show Shared Secrets</a>', '6', '3', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort order of display.', 'MODULE_PAYMENT_COINBASE_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '5', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Pending Order Status', 'MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID', '0', 'Set the status of orders made with this payment module that are not yet completed to this value<br />(\'Pending\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Expired Order Status', 'MODULE_PAYMENT_COINBASE_EXPIRED_STATUS_ID', '0', 'Set the status of orders made with this payment module that have expired<br />(\'Expired\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Canceled Order Status', 'MODULE_PAYMENT_COINBASE_CANCELED_STATUS_ID', '0', 'Set the status of orders made with this payment module that have been canceled<br />(\'Canceled\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Unresolved Order Status', 'MODULE_PAYMENT_COINBASE_UNRESOLVED_STATUS_ID', '0', 'Set the status of orders made with this payment module that have been unresolved', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
        tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Complete Order Status', 'MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID', '2', 'Set the status of orders made with this payment module that have completed payment to this value<br />(\'Processing\' recommended)', '6', '7', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
    }

    /**
     * Remove the module and all its settings
     *
     */
    function remove()
    {
        tep_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
    }

    /**
     * Internal list of configuration keys used for configuration of the module
     *
     * @return array
     */
    function keys()
    {
        return array(
            'MODULE_PAYMENT_COINBASE_STATUS',
            'MODULE_PAYMENT_COINBASE_API_KEY',
            'MODULE_PAYMENT_COINBASE_SHARED_SECRET',
            'MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_EXPIRED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_CANCELED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_UNRESOLVED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_SORT_ORDER'
        );
    }
}
?>
