<?php
/**
 * Plugin Name: SA WooCommerce Installments
 * Description: Adds a custom table for handling installment payments with different banks.
 * Version: 1.0
 * Author: Akin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SA_WooCommerce_Installments {

    public static function init() {
        register_activation_hook( __FILE__, array( __CLASS__, 'create_installments_table' ) );
        add_action( 'admin_menu', array( __CLASS__, 'installments_menu' ) );
        add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'display_installment_table' ) );
        add_action( 'wp_head', array( __CLASS__, 'installment_table_styles' ) );
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'installment_rates';
    }

    public static function create_installments_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            bank_name varchar(255) NOT NULL,
            installment_number int NOT NULL,
            rate decimal(10,4) NOT NULL,
            additional_installments int NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function installments_menu() {
        add_menu_page(
            'Installment Rates',
            'Installments',
            'manage_options',
            'installments-settings',
            array( __CLASS__, 'installments_settings_page' )
        );
    }

    public static function installments_settings_page() {
        global $wpdb;
        $table_name = self::get_table_name();
        $edit_rate  = null;

        // Import rates from textarea
        if ( isset( $_POST['import'] ) && check_admin_referer( 'import_installment_rates' ) ) {
            $excel_data = $_POST['excel_data'];
            $rows       = explode( "\n", $excel_data );

            foreach ( $rows as $row ) {
                $columns = array_map( 'trim', explode( "\t", $row ) );

                if ( count( $columns ) >= 5 ) {
                    $bank_name              = sanitize_text_field( $columns[0] );
                    $installment_number     = intval( $columns[1] );
                    $rate                   = floatval( $columns[2] );
                    $additional_installments = intval( $columns[3] );
                    $is_default             = strtolower( $columns[4] ) === 'yes' ? 1 : 0;

                    $existing_record = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM $table_name WHERE bank_name = %s AND installment_number = %d",
                            $bank_name,
                            $installment_number
                        )
                    );

                    $data_array = array(
                        'bank_name'              => $bank_name,
                        'installment_number'     => $installment_number,
                        'rate'                   => $rate,
                        'additional_installments'=> $additional_installments,
                        'is_default'             => $is_default,
                    );

                    if ( null !== $existing_record ) {
                        $wpdb->update( $table_name, $data_array, array( 'id' => $existing_record->id ) );
                    } else {
                        $wpdb->insert( $table_name, $data_array );
                    }
                }
            }

            echo '<div class="notice notice-success"><p>Installment rates imported successfully.</p></div>';
        }

        // Handle delete action
        if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
            $id = intval( $_GET['id'] );
            check_admin_referer( 'delete_rate_' . $id );
            $wpdb->delete( $table_name, array( 'id' => $id ) );
        }

        // Handle edit action
        if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
            $id        = intval( $_GET['id'] );
            $edit_rate = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
        }

        // Form submission for new/edit rates
        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'installments_add_new' );

            $bank_name              = sanitize_text_field( $_POST['bank_name'] );
            $installment_number     = intval( $_POST['installment_number'] );
            $rate                   = floatval( $_POST['rate'] );
            $additional_installments = intval( $_POST['additional_installments'] );
            $is_default             = isset( $_POST['is_default'] ) ? 1 : 0;

            if ( $is_default ) {
                $wpdb->update( $table_name, array( 'is_default' => 0 ), array( 'bank_name' => $bank_name ) );
            }

            $data = array(
                'bank_name'              => $bank_name,
                'installment_number'     => $installment_number,
                'rate'                   => $rate,
                'additional_installments'=> $additional_installments,
                'is_default'             => $is_default,
            );

            if ( isset( $_POST['rate_id'] ) && intval( $_POST['rate_id'] ) > 0 ) {
                $wpdb->update( $table_name, $data, array( 'id' => intval( $_POST['rate_id'] ) ) );
            } else {
                $wpdb->insert( $table_name, $data );
            }
        }

        $existing_rates = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY bank_name,installment_number" );
        $is_editing     = isset( $edit_rate ) && $edit_rate;

        echo '<div class="wrap"><h1>' . ( $is_editing ? 'Edit Installment Rate' : 'Add New Installment Rate' ) . '</h1>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'installments_add_new' );

        if ( $is_editing ) {
            echo '<input type="hidden" name="rate_id" value="' . esc_attr( $edit_rate->id ) . '">';
        }

        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="bank_name">Bank Name</label></th>
                <td><input name="bank_name" id="bank_name" type="text" value="' . ( $is_editing ? esc_attr( $edit_rate->bank_name ) : '' ) . '" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="installment_number">Number of Installments</label></th>
                <td><input name="installment_number" id="installment_number" type="number" value="' . ( $is_editing ? esc_attr( $edit_rate->installment_number ) : '' ) . '" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="rate">Rate</label></th>
                <td><input name="rate" id="rate" type="text" value="' . ( $is_editing ? esc_attr( $edit_rate->rate ) : '' ) . '" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="additional_installments">Additional Installments</label></th>
                <td><input name="additional_installments" id="additional_installments" type="number" value="' . ( $is_editing ? esc_attr( $edit_rate->additional_installments ) : '' ) . '" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="is_default">Is Default</label></th>
                <td><input name="is_default" id="is_default" type="checkbox" ' . ( $is_editing && $edit_rate->is_default ? 'checked' : '' ) . '></td>
            </tr>
        </table>';

        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . ( $is_editing ? 'Update Rate' : 'Add Rate' ) . '"></p>';
        echo '</form>';

        echo '<h2>Import Installment Rates from Excel</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'import_installment_rates' );
        echo '<textarea name="excel_data" rows="10" cols="50"></textarea>';
        echo '<p><input type="submit" name="import" value="Import Data" class="button button-primary"></p>';
        echo '</form>';

        if ( $existing_rates ) {
            echo '<h2>Existing Rates</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Bank Name</th><th>Number of Installments</th><th>Rate</th><th>Additional Installments</th><th>Toplam</th><th>Is Default</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ( $existing_rates as $rate ) {
                $delete_nonce = wp_create_nonce( 'delete_rate_' . $rate->id );
                $edit_nonce   = wp_create_nonce( 'edit_rate_' . $rate->id );

                $delete_link = admin_url( 'admin.php?page=installments-settings&action=delete&id=' . $rate->id . '&_wpnonce=' . $delete_nonce );
                $edit_link   = admin_url( 'admin.php?page=installments-settings&action=edit&id=' . $rate->id . '&_wpnonce=' . $edit_nonce );

                echo '<tr>';
                echo '<td>' . esc_html( $rate->bank_name ) . '</td>';
                echo '<td>' . esc_html( $rate->installment_number ) . '</td>';
                echo '<td>' . esc_html( $rate->rate ) . '%</td>';
                echo '<td>' . esc_html( $rate->additional_installments ) . '</td>';
                echo '<td>' . esc_html( $rate->additional_installments + $rate->installment_number ) . '</td>';
                echo '<td>' . ( $rate->is_default ? 'Yes' : 'No' ) . '</td>';
                echo '<td><a href="' . esc_url( $edit_link ) . '">Edit</a> | <a href="' . esc_url( $delete_link ) . '">Delete</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No installment rates found.</p>';
        }
        echo '</div>';
    }

    public static function installment_table_styles() {
        echo '<style>
            .installment-table {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            .installment-table .default-rate {
                background-color: #c0ffc0;
            }
            .installment-table tr.group-start td {
                border-top: 2px solid #333;
            }
        </style>';
    }

    public static function display_installment_table() {
        global $wpdb;
        $table_name = self::get_table_name();

        $installment_rates = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY bank_name, installment_number" );

        $default_rates = array();
        $bank_rows     = array();
        foreach ( $installment_rates as $rate ) {
            if ( $rate->is_default ) {
                $default_rates[ $rate->bank_name ] = $rate->rate;
            }
            if ( ! isset( $bank_rows[ $rate->bank_name ] ) ) {
                $bank_rows[ $rate->bank_name ] = 0;
            }
            $bank_rows[ $rate->bank_name ]++;
        }

        $cart_total = WC()->cart->get_subtotal();

        echo '<h2>Installment Options</h2>';
        echo '<table class="shop_table shop_table_responsive installment-table">';
        echo '<thead><tr><th>Bank</th><th>Number of Installments</th><th>Ek Taksit</th><th>Toplam Taksit</th><th>Per Installment</th><th>Total</th></tr></thead>';
        echo '<tbody>';

        $previous_bank_name = '';
        foreach ( $installment_rates as $rate ) {
            $bank_default_rate = isset( $default_rates[ $rate->bank_name ] ) ? $default_rates[ $rate->bank_name ] : 0;
            $adjusted_total    = $cart_total;

            if ( $rate->rate > $bank_default_rate ) {
                $adjusted_total = $cart_total / ( 1 - $rate->rate / 100 ) * ( 1 - $bank_default_rate / 100 );
            }

            $total_installments = $rate->installment_number + $rate->additional_installments;
            $per_installment    = $adjusted_total / $total_installments;

            $is_default_rate = $rate->rate == $bank_default_rate;
            $class           = $is_default_rate ? 'default-rate' : '';

            echo '<tr class="' . $class . '">';

            if ( $rate->bank_name !== $previous_bank_name ) {
                if ( '' !== $previous_bank_name ) {
                    echo '</tbody><tbody>';
                }
                echo '<td rowspan="' . $bank_rows[ $rate->bank_name ] . '">' . esc_html( $rate->bank_name ) . '</td>';
            }

            echo '<td>' . esc_html( $rate->installment_number ) . '</td>';
            echo '<td>' . esc_html( $rate->additional_installments ) . '</td>';
            echo '<td>' . esc_html( $total_installments ) . '</td>';
            echo '<td>' . wc_price( $per_installment ) . '</td>';
            echo '<td>' . wc_price( $adjusted_total ) . '</td>';
            echo '</tr>';

            $previous_bank_name = $rate->bank_name;
        }

        echo '</tbody></table>';
    }
}

SA_WooCommerce_Installments::init();

