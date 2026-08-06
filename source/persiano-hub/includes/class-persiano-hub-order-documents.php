<?php
/** Printable order summaries and invoices. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Order_Documents {
    const PAGE_SLUG = 'persiano-hub-order-documents';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 26 );
        add_action( 'admin_post_persiano_hub_print_order_document', array( __CLASS__, 'handle_print' ) );
        add_action( 'admin_post_nopriv_persiano_hub_customer_invoice', array( __CLASS__, 'handle_customer_invoice' ) );
        add_action( 'admin_post_persiano_hub_customer_invoice', array( __CLASS__, 'handle_customer_invoice' ) );
        add_action( 'init', array( __CLASS__, 'account_endpoint' ) );
        add_filter( 'query_vars', array( __CLASS__, 'account_query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_item' ) );
        add_action( 'woocommerce_account_persiano-invoices_endpoint', array( __CLASS__, 'account_invoices' ) );
        add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_order_action' ), 20, 2 );
        add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'order_action' ), 20, 2 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_buttons' ) );
    }



    private static function pdf_text( $text ) {
        $text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
            if ( false !== $converted ) { $text = $converted; }
        }
        return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $text );
    }

    private static function pdf_money( $amount, $currency ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
    }

    /** Convert a GD image into a one-page PDF with the image filling US Letter. */
    private static function image_pdf_bytes( $image ) {
        ob_start();
        imagejpeg( $image, null, 92 );
        $jpg = ob_get_clean();
        $width = imagesx( $image );
        $height = imagesy( $image );
        $content = "q\n612 0 0 792 0 0 cm\n/Im0 Do\nQ\n";
        $objects = array(
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen( $content ) . ">>\nstream\n" . $content . "endstream",
            '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $jpg ) . ">>\nstream\n" . $jpg . "\nendstream",
        );
        $pdf = "%PDF-1.4\n"; $offsets = array( 0 );
        foreach ( $objects as $i => $obj ) { $offsets[] = strlen( $pdf ); $pdf .= ( $i + 1 ) . " 0 obj\n" . $obj . "\nendobj\n"; }
        $xref = strlen( $pdf );
        $pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
        for ( $i = 1; $i <= count( $objects ); $i++ ) { $pdf .= sprintf( "%010d 00000 n \n", $offsets[$i] ); }
        $pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private static function gd_text( $im, $x, $y, $text, $colour, $font = 5 ) {
        $text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
        imagestring( $im, $font, (int) $x, (int) $y, $text, $colour );
    }

    private static function gd_wrap( $im, $x, $y, $text, $colour, $font = 4, $chars = 58, $line_height = 22 ) {
        $lines = explode( "\n", wordwrap( wp_strip_all_tags( (string) $text ), $chars, "\n", true ) );
        foreach ( $lines as $line ) { self::gd_text( $im, $x, $y, $line, $colour, $font ); $y += $line_height; }
        return $y;
    }

    /** Build a branded legal invoice PDF. Falls back to the compact text PDF when GD is unavailable. */
    public static function generate_pdf_bytes( WC_Order $order, $type = 'invoice' ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
            return self::generate_plain_pdf_bytes( $order, $type );
        }
        $settings = wp_parse_args( get_option( 'persiano_hub_frontend_pos_settings', array() ), array(
            'business_legal_name' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::legal_name() : ( get_bloginfo( 'name' ) ?: 'Business' ), 'business_address' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::address() : '', 'business_gst' => '',
            'business_phone' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_phone() : '', 'business_email' => function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : get_option( 'admin_email' ),
        ) );
        $is_credit = 'credit-note' === $type;
        $refunded = (float) $order->get_total_refunded();
        $total = (float) $order->get_total();
        $fully_refunded = $refunded > 0 && $refunded >= ( $total - 0.001 );
        $part_refunded = $refunded > 0 && ! $fully_refunded;
        $stamp = $is_credit ? 'CREDIT NOTE' : ( $fully_refunded ? 'REFUNDED' : ( $part_refunded ? 'PARTIALLY REFUNDED' : ( $order->is_paid() ? 'PAID' : strtoupper( wc_get_order_status_name( $order->get_status() ) ) ) ) );

        $im = imagecreatetruecolor( 1275, 1650 );
        $white = imagecolorallocate( $im, 255, 255, 255 );
        $ink = imagecolorallocate( $im, 35, 31, 26 );
        $muted = imagecolorallocate( $im, 105, 98, 91 );
        $brand = imagecolorallocate( $im, 145, 38, 55 );
        $cream = imagecolorallocate( $im, 248, 244, 237 );
        $green = imagecolorallocate( $im, 35, 134, 54 );
        $amber = imagecolorallocate( $im, 176, 112, 24 );
        $grey = imagecolorallocate( $im, 218, 213, 205 );
        imagefilledrectangle( $im, 0, 0, 1275, 1650, $white );
        imagefilledrectangle( $im, 0, 0, 1275, 24, $brand );

        $logo_url = class_exists( 'Persiano_Hub_Email_Branding' ) ? Persiano_Hub_Email_Branding::get_logo_url() : '';
        if ( $logo_url ) {
            $response = wp_remote_get( $logo_url, array( 'timeout' => 8 ) );
            if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
                $logo = @imagecreatefromstring( wp_remote_retrieve_body( $response ) );
                if ( $logo ) {
                    $lw = imagesx( $logo ); $lh = imagesy( $logo );
                    $maxw = 170; $maxh = 110; $scale = min( $maxw / max( 1, $lw ), $maxh / max( 1, $lh ) );
                    imagecopyresampled( $im, $logo, 70, 55, 0, 0, (int)( $lw * $scale ), (int)( $lh * $scale ), $lw, $lh );
                    imagedestroy( $logo );
                }
            }
        }
        self::gd_text( $im, 70, 180, $settings['business_legal_name'], $ink, 5 );
        $y = 210;
        foreach ( preg_split( '/\R/', (string) $settings['business_address'] ) as $line ) { if ( trim( $line ) ) { self::gd_text( $im, 70, $y, trim( $line ), $ink, 4 ); $y += 22; } }
        if ( $settings['business_gst'] ) { self::gd_text( $im, 70, $y, 'GST: ' . $settings['business_gst'], $ink, 4 ); $y += 22; }
        if ( $settings['business_phone'] ) { self::gd_text( $im, 70, $y, $settings['business_phone'], $ink, 4 ); $y += 22; }
        if ( $settings['business_email'] ) { self::gd_text( $im, 70, $y, $settings['business_email'], $ink, 4 ); }

        self::gd_text( $im, 880, 62, $is_credit ? 'CREDIT NOTE' : 'INVOICE', $brand, 5 );
        self::gd_text( $im, 955, 110, '#' . $order->get_order_number(), $ink, 5 );
        $stamp_colour = $fully_refunded ? $brand : ( $part_refunded ? $amber : $green );
        imagerectangle( $im, 895, 165, 1195, 235, $stamp_colour );
        imagerectangle( $im, 899, 169, 1191, 231, $stamp_colour );
        self::gd_text( $im, 930, 190, $stamp, $stamp_colour, 5 );
        imagefilledrectangle( $im, 70, 330, 1205, 334, $brand );

        imagefilledrectangle( $im, 70, 370, 610, 590, $cream );
        imagerectangle( $im, 70, 370, 610, 590, $grey );
        imagefilledrectangle( $im, 635, 370, 1205, 590, $cream );
        imagerectangle( $im, 635, 370, 1205, 590, $grey );
        self::gd_text( $im, 95, 392, 'ORDER INFORMATION', $brand, 5 );
        self::gd_text( $im, 95, 435, 'Order date: ' . ( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ), $ink, 4 );
        self::gd_text( $im, 95, 470, 'Status: ' . wc_get_order_status_name( $order->get_status() ), $ink, 4 );
        self::gd_text( $im, 660, 392, 'PAYMENT INFORMATION', $brand, 5 );
        self::gd_text( $im, 660, 435, 'Method: ' . ( $order->get_payment_method_title() ?: 'Recorded payment' ), $ink, 4 );
        if ( $order->get_date_paid() ) { self::gd_text( $im, 660, 470, 'Paid on: ' . wc_format_datetime( $order->get_date_paid() ), $ink, 4 ); }
        if ( $order->get_transaction_id() ) { self::gd_wrap( $im, 660, 505, 'Transaction: ' . $order->get_transaction_id(), $muted, 3, 54, 18 ); }

        imagefilledrectangle( $im, 70, 620, 610, 810, $white ); imagerectangle( $im, 70, 620, 610, 810, $grey );
        imagefilledrectangle( $im, 635, 620, 1205, 810, $white ); imagerectangle( $im, 635, 620, 1205, 810, $grey );
        self::gd_text( $im, 95, 642, 'BILL TO', $brand, 5 );
        $bill_lines = array_filter( array( $order->get_formatted_billing_full_name(), $order->get_billing_address_1(), $order->get_billing_address_2(), trim( $order->get_billing_city() . ' ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode() ), $order->get_billing_email(), $order->get_billing_phone() ) );
        $yy = 685; foreach ( $bill_lines as $line ) { $yy = self::gd_wrap( $im, 95, $yy, $line, $ink, 4, 48, 21 ); }
        self::gd_text( $im, 660, 642, 'DELIVER / PREPARE FOR', $brand, 5 );
        $ship_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $ship_lines = array_filter( array( $ship_name ?: $order->get_formatted_billing_full_name(), $order->get_shipping_address_1() ?: $order->get_billing_address_1(), $order->get_shipping_address_2(), trim( ( $order->get_shipping_city() ?: $order->get_billing_city() ) . ' ' . ( $order->get_shipping_state() ?: $order->get_billing_state() ) . ' ' . ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) ) ) );
        $yy = 685; foreach ( $ship_lines as $line ) { $yy = self::gd_wrap( $im, 660, $yy, $line, $ink, 4, 48, 21 ); }

        imagefilledrectangle( $im, 70, 845, 1205, 890, $cream );
        self::gd_text( $im, 90, 858, 'ITEM', $ink, 4 ); self::gd_text( $im, 710, 858, 'QTY', $ink, 4 ); self::gd_text( $im, 835, 858, 'UNIT', $ink, 4 ); self::gd_text( $im, 1060, 858, 'TOTAL', $ink, 4 );
        $row_y = 910;
        foreach ( $order->get_items() as $item ) {
            if ( $row_y > 1225 ) { self::gd_text( $im, 90, $row_y, 'Additional items omitted from this one-page attachment.', $muted, 3 ); break; }
            $qty = (float) $item->get_quantity();
            $unit = $qty ? ( (float) $item->get_subtotal() / $qty ) : 0;
            self::gd_wrap( $im, 90, $row_y, $item->get_name(), $ink, 4, 56, 20 );
            $product = $item->get_product(); if ( $product && $product->get_sku() ) { self::gd_text( $im, 90, $row_y + 24, 'SKU ' . $product->get_sku(), $muted, 3 ); }
            self::gd_text( $im, 720, $row_y, wc_format_decimal( $qty, 2 ), $ink, 4 );
            self::gd_text( $im, 835, $row_y, self::pdf_money( $unit, $order->get_currency() ), $ink, 4 );
            self::gd_text( $im, 1060, $row_y, self::pdf_money( (float) $item->get_total() + (float) $item->get_total_tax(), $order->get_currency() ), $ink, 4 );
            imageline( $im, 70, $row_y + 55, 1205, $row_y + 55, $grey ); $row_y += 70;
        }

        $tot_y = max( 1260, $row_y + 10 );
        self::gd_text( $im, 820, $tot_y, 'Subtotal:', $ink, 4 ); self::gd_text( $im, 1060, $tot_y, self::pdf_money( $order->get_subtotal(), $order->get_currency() ), $ink, 4 ); $tot_y += 34;
        if ( (float) $order->get_total_tax() ) { self::gd_text( $im, 820, $tot_y, 'Tax:', $ink, 4 ); self::gd_text( $im, 1060, $tot_y, self::pdf_money( $order->get_total_tax(), $order->get_currency() ), $ink, 4 ); $tot_y += 34; }
        foreach ( $order->get_fees() as $fee ) { self::gd_text( $im, 820, $tot_y, $fee->get_name() . ':', $ink, 4 ); self::gd_text( $im, 1060, $tot_y, self::pdf_money( $fee->get_total() + $fee->get_total_tax(), $order->get_currency() ), $ink, 4 ); $tot_y += 34; }
        imagefilledrectangle( $im, 800, $tot_y - 4, 1205, $tot_y, $brand );
        self::gd_text( $im, 820, $tot_y + 18, 'TOTAL:', $ink, 5 ); self::gd_text( $im, 1030, $tot_y + 18, self::pdf_money( $total, $order->get_currency() ) . ' ' . $order->get_currency(), $ink, 5 ); $tot_y += 60;
        if ( $refunded > 0 ) {
            self::gd_text( $im, 820, $tot_y, 'Refunded:', $brand, 4 ); self::gd_text( $im, 1060, $tot_y, self::pdf_money( $refunded, $order->get_currency() ), $brand, 4 ); $tot_y += 34;
            self::gd_text( $im, 820, $tot_y, 'Net retained:', $ink, 4 ); self::gd_text( $im, 1060, $tot_y, self::pdf_money( max( 0, $total - $refunded ), $order->get_currency() ), $ink, 4 );
        }
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' );
        self::gd_text( $im, 430, 1585, $is_credit ? sprintf( 'Credit note issued by %s.', $brand ) : sprintf( 'Thank you for your order from %s.', $brand ), $muted, 3 );
        $pdf = self::image_pdf_bytes( $im ); imagedestroy( $im ); return $pdf;
    }

    /** Original compact PDF fallback for hosts without GD. */
    private static function generate_plain_pdf_bytes( WC_Order $order, $type = 'invoice' ) {
        $settings = wp_parse_args( get_option( 'persiano_hub_frontend_pos_settings', array() ), array(
            'business_legal_name' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::legal_name() : ( get_bloginfo( 'name' ) ?: 'Business' ), 'business_address' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::address() : '', 'business_gst' => '',
            'business_phone' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_phone() : '', 'business_email' => function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : get_option( 'admin_email' ),
        ) );
        $refunded = (float) $order->get_total_refunded(); $total = (float) $order->get_total();
        $fully_refunded = $refunded > 0 && $refunded >= ( $total - 0.001 ); $part_refunded = $refunded > 0 && ! $fully_refunded;
        $is_credit = 'credit-note' === $type; $title = $is_credit ? 'CREDIT NOTE' : 'INVOICE';
        $stamp = $is_credit ? 'CREDIT NOTE' : ( $fully_refunded ? 'REFUNDED' : ( $part_refunded ? 'PARTIALLY REFUNDED' : ( $order->is_paid() ? 'PAID' : strtoupper( wc_get_order_status_name( $order->get_status() ) ) ) ) );
        $lines = array( array(16,$settings['business_legal_name']) );
        foreach ( preg_split( '/\R/', (string) $settings['business_address'] ) as $line ) { if ( trim( $line ) ) { $lines[] = array(10,trim($line)); } }
        if($settings['business_gst']){$lines[]=array(10,'GST: '.$settings['business_gst']);} if($settings['business_phone']){$lines[]=array(10,$settings['business_phone']);} if($settings['business_email']){$lines[]=array(10,$settings['business_email']);}
        $lines[]=array(8,''); $lines[]=array(18,$title.' #'.$order->get_order_number()); $lines[]=array(10,'Order date: '.($order->get_date_created()?wc_format_datetime($order->get_date_created()):'')); $lines[]=array(10,'Status: '.wc_get_order_status_name($order->get_status()));
        if($order->get_date_paid()){$lines[]=array(10,'Paid on: '.wc_format_datetime($order->get_date_paid()));} $lines[]=array(10,'Payment method: '.($order->get_payment_method_title()?:'Recorded payment')); if($order->get_transaction_id()){$lines[]=array(9,'Transaction ID: '.$order->get_transaction_id());}
        $lines[]=array(8,''); $bill=trim($order->get_formatted_billing_full_name()); if($bill){$lines[]=array(12,'Bill to: '.$bill);} if($order->get_billing_email()){$lines[]=array(9,$order->get_billing_email());} if($order->get_billing_phone()){$lines[]=array(9,$order->get_billing_phone());}
        $lines[]=array(8,''); $lines[]=array(11,'ITEMS'); foreach($order->get_items() as $item){$qty=(float)$item->get_quantity();$line=$item->get_name().' x '.wc_format_decimal($qty,2).' '.self::pdf_money((float)$item->get_total()+(float)$item->get_total_tax(),$order->get_currency());$lines[]=array(10,$line);} $lines[]=array(8,'');
        $lines[]=array(10,'Subtotal: '.self::pdf_money($order->get_subtotal(),$order->get_currency())); if((float)$order->get_total_tax()){$lines[]=array(10,'Tax: '.self::pdf_money($order->get_total_tax(),$order->get_currency()));} foreach($order->get_fees() as $fee){$lines[]=array(10,$fee->get_name().': '.self::pdf_money($fee->get_total()+$fee->get_total_tax(),$order->get_currency()));}
        $lines[]=array(14,'Total: '.self::pdf_money($total,$order->get_currency()).' '.$order->get_currency()); if($refunded>0){$lines[]=array(11,'Refunded: '.self::pdf_money($refunded,$order->get_currency()));$lines[]=array(11,'Net retained: '.self::pdf_money(max(0,$total-$refunded),$order->get_currency()));} $lines[]=array(8,'');$lines[]=array(12,$stamp);
        $content="BT\n";$y=770;foreach($lines as $row){$size=(int)$row[0];$text=self::pdf_text($row[1]);if($y<55){break;}$content.='/F1 '.$size." Tf\n1 0 0 1 50 ".$y." Tm\n(".$text.") Tj\n";$y-=max(13,$size+4);} $content.="ET\n";
        $objects=array('<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>','<< /Length '.strlen($content).">>\nstream\n".$content.'endstream','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $pdf="%PDF-1.4\n";$offsets=array(0);foreach($objects as $i=>$obj){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++){$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);}$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";return $pdf;
    }

    public static function generate_pdf_file( WC_Order $order, $type = 'invoice' ) {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) { return new WP_Error( 'upload_error', $upload['error'] ); }
        $dir = trailingslashit( $upload['basedir'] ) . 'persiano-invoices';
        if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'invoice_dir', 'Could not create the invoice directory.' ); }
        $prefix = 'credit-note' === $type ? 'Persiano-Credit-Note-' : 'Persiano-Invoice-';
        $path = trailingslashit( $dir ) . sanitize_file_name( $prefix . $order->get_order_number() . '.pdf' );
        $written = file_put_contents( $path, self::generate_pdf_bytes( $order, $type ), LOCK_EX );
        return false === $written ? new WP_Error( 'invoice_write', 'Could not write the PDF invoice.' ) : $path;
    }

    public static function account_endpoint() {
        add_rewrite_endpoint( 'persiano-invoices', EP_ROOT | EP_PAGES );
    }

    public static function account_query_vars( $vars ) {
        $vars[] = 'persiano-invoices';
        return $vars;
    }

    public static function account_menu_item( $items ) {
        $out = array();
        foreach ( $items as $key => $label ) {
            $out[ $key ] = $label;
            if ( 'orders' === $key ) { $out['persiano-invoices'] = __( 'Invoices', 'persiano-hub' ); }
        }
        return $out;
    }

    public static function account_order_action( $actions, $order ) {
        if ( $order instanceof WC_Order ) {
            if ( $order->is_paid() || $order->get_total_refunded() > 0 ) {
                $actions['persiano_invoice'] = array( 'url' => self::customer_document_url( $order, 'invoice' ), 'name' => __( 'Invoice', 'persiano-hub' ) );
            } else {
                $actions['persiano_summary'] = array( 'url' => self::customer_document_url( $order, 'summary' ), 'name' => __( 'Order summary', 'persiano-hub' ) );
            }
        }
        return $actions;
    }

    public static function account_invoices() {
        $customer_id = get_current_user_id();
        if ( ! $customer_id ) { return; }
        $all_orders = wc_get_orders( array( 'customer_id' => $customer_id, 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
        $orders = array_values( array_filter( $all_orders, static function( $order ) { return $order instanceof WC_Order && ( $order->is_paid() || $order->get_total_refunded() > 0 ); } ) );
        echo '<h2>' . esc_html__( 'Invoices', 'persiano-hub' ) . '</h2>';
        if ( ! $orders ) { echo '<p>' . esc_html__( 'No invoices are available yet.', 'persiano-hub' ) . '</p>'; return; }
        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'Order', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Date', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Status', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Total', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Invoice', 'persiano-hub' ) . '</th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            echo '<tr><td>#' . esc_html( $order->get_order_number() ) . '</td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td><a class="button" target="_blank" href="' . esc_url( self::customer_document_url( $order, 'invoice' ) ) . '">' . esc_html__( 'View / print', 'persiano-hub' ) . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function customer_document_url( $order, $type = 'invoice' ) {
        if ( is_numeric( $order ) ) { $order = wc_get_order( absint( $order ) ); }
        if ( ! $order instanceof WC_Order ) { return ''; }
        return add_query_arg( array( 'action' => 'persiano_hub_customer_invoice', 'order_id' => $order->get_id(), 'key' => $order->get_order_key(), 'document_type' => $type ), admin_url( 'admin-post.php' ) );
    }

    public static function handle_customer_invoice() {
        $order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
        $key   = wc_clean( wp_unslash( $_GET['key'] ?? '' ) );
        if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $key ) ) { wp_die( esc_html__( 'Invoice access could not be verified.', 'persiano-hub' ), 403 ); }
        if ( is_user_logged_in() && ! current_user_can( 'manage_woocommerce' ) && (int) $order->get_customer_id() !== get_current_user_id() ) { wp_die( esc_html__( 'You do not have permission to view this invoice.', 'persiano-hub' ), 403 ); }
        self::render_document( $order, 'invoice' );
        exit;
    }

    public static function admin_menu() {
        add_submenu_page( 'persiano-hub', __( 'Order Documents', 'persiano-hub' ), __( 'Order Documents', 'persiano-hub' ), 'manage_woocommerce', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
    }

    public static function document_url( $order_id, $type = 'summary' ) {
        return wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_print_order_document', 'order_id'=>absint($order_id), 'document_type'=>$type ), admin_url( 'admin-post.php' ) ), 'persiano_hub_print_order_document_' . absint($order_id) );
    }

    public static function order_action( $actions, $order ) {
        if ( ! $order instanceof WC_Order ) { return $actions; }
        $actions['persiano_summary'] = array( 'url'=>self::document_url($order->get_id(),'summary'), 'name'=>__( 'Print order summary', 'persiano-hub' ), 'action'=>'view persiano-summary' );
        $actions['persiano_invoice'] = array( 'url'=>self::document_url($order->get_id(),'invoice'), 'name'=>__( 'Print invoice', 'persiano-hub' ), 'action'=>'view persiano-invoice' );
        return $actions;
    }

    public static function order_buttons( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Persiano documents', 'persiano-hub' ) . '</strong><br>';
        echo '<a class="button" target="_blank" href="' . esc_url( self::document_url($order->get_id(),'summary') ) . '">' . esc_html__( 'Print order summary', 'persiano-hub' ) . '</a> ';
        echo '<a class="button" target="_blank" href="' . esc_url( self::document_url($order->get_id(),'invoice') ) . '">' . esc_html__( 'Print invoice', 'persiano-hub' ) . '</a></p>';
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'persiano-hub' ) ); }
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        ?>
        <div class="wrap"><h1><?php esc_html_e('Order Summary & Invoice','persiano-hub'); ?></h1>
        <p><?php esc_html_e('Enter a WooCommerce order number, then open a printable kitchen/order summary or customer invoice.','persiano-hub'); ?></p>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;max-width:720px">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <label><strong><?php esc_html_e('Order number','persiano-hub'); ?></strong><br><input type="number" name="order_id" min="1" value="<?php echo esc_attr($order_id); ?>" style="width:240px"></label>
            <button class="button button-primary"><?php esc_html_e('Find order','persiano-hub'); ?></button>
        </form>
        <?php if ($order_id) : $order=wc_get_order($order_id); if($order): ?>
            <hr><h2><?php echo esc_html(sprintf(__('Order #%s','persiano-hub'),$order->get_order_number())); ?></h2>
            <p><?php echo esc_html($order->get_formatted_billing_full_name()); ?> · <?php echo wp_kses_post(wc_price($order->get_total(),array('currency'=>$order->get_currency()))); ?></p>
            <a class="button button-primary" target="_blank" href="<?php echo esc_url(self::document_url($order_id,'summary')); ?>"><?php esc_html_e('Print order summary','persiano-hub'); ?></a>
            <a class="button" target="_blank" href="<?php echo esc_url(self::document_url($order_id,'invoice')); ?>"><?php esc_html_e('Print invoice','persiano-hub'); ?></a>
        <?php else: ?><div class="notice notice-error inline"><p><?php esc_html_e('Order not found.','persiano-hub'); ?></p></div><?php endif; endif; ?>
        </div></div><?php
    }

    public static function handle_print() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'persiano-hub' ) ); }
        $order_id=isset($_GET['order_id'])?absint($_GET['order_id']):0;
        check_admin_referer('persiano_hub_print_order_document_'.$order_id);
        $order=wc_get_order($order_id); if(!$order){wp_die(esc_html__('Order not found.','persiano-hub'));}
        $type=sanitize_key(wp_unslash($_GET['document_type']??'summary')); if(!in_array($type,array('summary','invoice'),true)){$type='summary';}
        self::render_document($order,$type); exit;
    }

    public static function render_document( WC_Order $order, $type ) {
        nocache_headers();
        $is_invoice='invoice'===$type;
        $logo=class_exists('Persiano_Hub_Email_Branding')?Persiano_Hub_Email_Branding::get_logo_url():'';
        $biz = wp_parse_args( get_option( 'persiano_hub_frontend_pos_settings', array() ), array( 'business_legal_name'=>class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::legal_name() : ( get_bloginfo( 'name' ) ?: 'Business' ),'business_address'=>class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::address() : '','business_gst'=>'','business_phone'=>class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_phone() : '','business_email'=>function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : get_option('admin_email') ) );
        $paid=$order->is_paid(); $paid_date=$order->get_date_paid();
        $transaction=$order->get_transaction_id();
        $payment_title=$order->get_payment_method_title();
        $shipping=$order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
        $billing=$order->get_formatted_billing_address();
        $status=wc_get_order_status_name($order->get_status());
        ?><!doctype html><html><head><meta charset="utf-8"><title><?php echo esc_html(($is_invoice?'Invoice ':'Order Summary ').'#'.$order->get_order_number()); ?></title>
        <style>@page{size:letter;margin:.45in}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#231f1a;margin:0;font-size:12px}.toolbar{background:#231f1a;color:#fff;padding:10px 14px;margin:-.45in -.45in .3in;display:flex;justify-content:space-between}.toolbar button{padding:8px 14px;font-weight:700}.header{display:flex;justify-content:space-between;border-bottom:3px solid #8e2435;padding-bottom:14px}.logo{max-width:190px;max-height:70px}.doc-title{text-align:right}.doc-title h1{margin:0;color:#8e2435}.paid{display:inline-block;padding:6px 12px;border:2px solid #238636;color:#238636;font-weight:800;transform:rotate(-4deg);margin-top:8px}.meta,.addresses{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:18px 0}.box{border:1px solid #ddd;border-radius:8px;padding:12px}.box h3{margin:0 0 8px;color:#8e2435}table{width:100%;border-collapse:collapse;margin-top:14px}th,td{padding:9px 7px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}th{background:#f3eee9}.num{text-align:right;white-space:nowrap}.totals{width:42%;margin-left:auto}.totals td{padding:6px}.grand td{font-size:15px;font-weight:800;border-top:2px solid #8e2435}.notes{margin-top:18px}.footer{text-align:center;color:#666;border-top:1px solid #ddd;margin-top:24px;padding-top:10px}.no-print{display:block}@media print{.no-print{display:none}.toolbar{display:none}}</style></head><body>
        <div class="toolbar no-print"><strong><?php echo esc_html($is_invoice?__('Printable invoice','persiano-hub'):__('Printable order summary','persiano-hub')); ?></strong><button onclick="window.print()"><?php esc_html_e('Print','persiano-hub'); ?></button></div>
        <header class="header"><div><?php if($logo): ?><img class="logo" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ); ?>"><?php else: ?><h2><?php echo esc_html($biz['business_legal_name']); ?></h2><?php endif; ?><div><strong><?php echo esc_html($biz['business_legal_name']); ?></strong><br><?php echo nl2br(esc_html($biz['business_address'])); ?><?php if($biz['business_gst']): ?><br>GST: <?php echo esc_html($biz['business_gst']); ?><?php endif; ?><?php if($biz['business_phone']): ?><br><?php echo esc_html($biz['business_phone']); ?><?php endif; ?><br><?php echo esc_html( wp_parse_url( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::website_url() : home_url( '/' ), PHP_URL_HOST ) ); ?> · <?php echo esc_html($biz['business_email']); ?></div></div><div class="doc-title"><h1><?php echo esc_html($is_invoice?__('INVOICE','persiano-hub'):__('ORDER SUMMARY','persiano-hub')); ?></h1><h2>#<?php echo esc_html($order->get_order_number()); ?></h2><?php if($is_invoice&&$paid): ?><span class="paid"><?php esc_html_e('PAID','persiano-hub'); ?></span><?php endif; ?></div></header>
        <section class="meta"><div class="box"><h3><?php esc_html_e('Order information','persiano-hub'); ?></h3><div><strong><?php esc_html_e('Order date:','persiano-hub'); ?></strong> <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></div><div><strong><?php esc_html_e('Status:','persiano-hub'); ?></strong> <?php echo esc_html($status); ?></div><?php if($order->get_customer_note()): ?><div><strong><?php esc_html_e('Customer note:','persiano-hub'); ?></strong> <?php echo esc_html($order->get_customer_note()); ?></div><?php endif; ?></div>
        <div class="box"><h3><?php esc_html_e('Payment information','persiano-hub'); ?></h3><div><strong><?php esc_html_e('Payment method:','persiano-hub'); ?></strong> <?php echo esc_html($payment_title?:__('Not recorded','persiano-hub')); ?></div><div><strong><?php esc_html_e('Payment status:','persiano-hub'); ?></strong> <?php echo esc_html($paid?__('Paid online','persiano-hub'):__('Not marked paid','persiano-hub')); ?></div><?php if($paid_date): ?><div><strong><?php esc_html_e('Paid on:','persiano-hub'); ?></strong> <?php echo esc_html(wc_format_datetime($paid_date)); ?></div><?php endif; ?><?php if($transaction): ?><div><strong><?php esc_html_e('Transaction ID:','persiano-hub'); ?></strong> <?php echo esc_html($transaction); ?></div><?php endif; ?><div><strong><?php esc_html_e('Currency:','persiano-hub'); ?></strong> <?php echo esc_html($order->get_currency()); ?></div></div></section>
        <?php if($is_invoice): ?><section class="addresses"><div class="box"><h3><?php esc_html_e('Bill to','persiano-hub'); ?></h3><?php echo wp_kses_post($billing); ?><br><?php echo esc_html($order->get_billing_email()); ?><br><?php echo esc_html($order->get_billing_phone()); ?></div><div class="box"><h3><?php esc_html_e('Deliver / prepare for','persiano-hub'); ?></h3><?php echo wp_kses_post($shipping); ?></div></section><?php endif; ?>
        <table><thead><tr><th><?php esc_html_e('Item','persiano-hub'); ?></th><th><?php esc_html_e('Details','persiano-hub'); ?></th><th class="num"><?php esc_html_e('Qty','persiano-hub'); ?></th><?php if($is_invoice): ?><th class="num"><?php esc_html_e('Unit price','persiano-hub'); ?></th><th class="num"><?php esc_html_e('Total','persiano-hub'); ?></th><?php endif; ?></tr></thead><tbody>
        <?php foreach($order->get_items() as $item): $product=$item->get_product(); $qty=$item->get_quantity(); ?><tr><td><strong><?php echo esc_html($item->get_name()); ?></strong><?php if($product&&$product->get_sku()): ?><br><small>SKU <?php echo esc_html($product->get_sku()); ?></small><?php endif; ?></td><td><?php echo wp_kses_post(wc_display_item_meta($item,array('echo'=>false,'separator'=>'<br>'))); ?></td><td class="num"><?php echo esc_html($qty); ?></td><?php if($is_invoice): ?><td class="num"><?php echo wp_kses_post(wc_price($qty?($item->get_subtotal()/$qty):0,array('currency'=>$order->get_currency()))); ?></td><td class="num"><?php echo wp_kses_post(wc_price($item->get_total()+$item->get_total_tax(),array('currency'=>$order->get_currency()))); ?></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table>
        <?php if($is_invoice): ?><table class="totals"><?php foreach($order->get_order_item_totals() as $key=>$total): ?><tr class="<?php echo 'order_total'===$key?'grand':''; ?>"><td><?php echo wp_kses_post($total['label']); ?></td><td class="num"><?php echo wp_kses_post($total['value']); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
        <?php if(!$is_invoice): ?><section class="notes box"><h3><?php esc_html_e('Fulfilment notes','persiano-hub'); ?></h3><p><?php echo $order->get_shipping_method()?esc_html(sprintf(__('Shipping / pickup: %s','persiano-hub'),$order->get_shipping_method())):esc_html__('No shipping method recorded.','persiano-hub'); ?></p><p><?php esc_html_e('Packed by: ____________________   Checked by: ____________________','persiano-hub'); ?></p></section><?php endif; ?>
        <footer class="footer"><?php $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ); echo esc_html( $is_invoice ? sprintf( __( 'Thank you for your order from %s.', 'persiano-hub' ), $brand ) : sprintf( __( 'Internal order preparation document — %s', 'persiano-hub' ), $brand ) ); ?></footer>
        </body></html><?php
    }
}
