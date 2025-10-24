<?php
namespace WPO\IPS;

use DOMDocument;
use WPO\IPS\Documents\BulkDocument;
use WPO\IPS\Documents\Invoice as InvoiceDocument;
use WPO\IPS\Documents\OrderDocument;
use WC_Abstract_Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\IPS\\CustomXmlExporter' ) ) :

class CustomXmlExporter {
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    protected static ?self $instance = null;

    /**
     * Retrieve singleton instance.
     */
    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register hooks.
     */
    public function __construct() {
        add_action( 'wpo_wcpdf_pdf_created', array( $this, 'maybe_export_invoice_xml' ), 10, 2 );
        add_filter( 'wpo_wcpdf_listing_actions', array( $this, 'add_invoice_xml_listing_action' ), 10, 2 );
        add_action( 'wp_ajax_wpo_wcpdf_download_invoice_xml', array( $this, 'handle_invoice_xml_download' ) );
        add_filter( 'wpo_wcpdf_document_output_formats', array( $this, 'register_invoice_xml_output_format' ), 10, 2 );
        add_filter( 'wpo_wcpdf_bulk_actions', array( $this, 'register_invoice_xml_bulk_action' ) );
        add_filter( 'option_wpo_wcpdf_documents_settings_invoice_xml', array( $this, 'ensure_invoice_xml_document_setting' ) );
        add_filter( 'default_option_wpo_wcpdf_documents_settings_invoice_xml', array( $this, 'default_invoice_xml_document_setting' ) );
        add_filter( 'wpo_wcpdf_output_format', array( $this, 'respect_invoice_xml_output_request' ), 10, 2 );
    }

    /**
     * Make sure the invoice document exposes XML as an output format.
     */
    public function register_invoice_xml_output_format( array $formats, $document ): array {
        if ( $document instanceof InvoiceDocument || ( $document instanceof BulkDocument && 'invoice' === $document->get_type() ) ) {
            if ( ! in_array( 'xml', $formats, true ) ) {
                $formats[] = 'xml';
            }
        }

        return $formats;
    }

    /**
     * Add the XML bulk action to the order table.
     */
    public function register_invoice_xml_bulk_action( array $actions ): array {
        if ( ! isset( $actions['invoice_xml'] ) ) {
            $actions['invoice_xml'] = __( 'XML Invoice', 'woocommerce-pdf-invoices-packing-slips' );
        }

        return $actions;
    }

    /**
     * Ensure the XML output format is treated as enabled.
     */
    public function ensure_invoice_xml_document_setting( $value ) {
        if ( empty( $value ) || ! is_array( $value ) ) {
            $value = array();
        }

        if ( ! isset( $value['enabled'] ) ) {
            $value['enabled'] = 'yes';
        }

        return $value;
    }

    /**
     * Provide default option values when the XML document settings have not been saved yet.
     */
    public function default_invoice_xml_document_setting( $default ) {
        if ( empty( $default ) || ! is_array( $default ) ) {
            $default = array();
        }

        return $this->ensure_invoice_xml_document_setting( $default );
    }

    /**
     * Ensure manual document generation honours XML output requests for invoices.
     */
    public function respect_invoice_xml_output_request( $output_format, $document ) {
        if ( 'pdf' !== $output_format ) {
            return $output_format;
        }

        $requested_output = '';

        if ( isset( $_REQUEST['output'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested_output = sanitize_key( wp_unslash( $_REQUEST['output'] ) );
        }

        if ( 'xml' !== $requested_output ) {
            return $output_format;
        }

        $is_invoice_document = $document instanceof InvoiceDocument;

        if ( ! $is_invoice_document && ! ( $document instanceof BulkDocument && 'invoice' === $document->get_type() ) ) {
            return $output_format;
        }

        if ( is_callable( array( $document, 'is_enabled' ) ) && ! $document->is_enabled( 'xml' ) ) {
            return $output_format;
        }

        if ( isset( $document->output_formats ) && is_array( $document->output_formats ) && ! in_array( 'xml', $document->output_formats, true ) ) {
            $document->output_formats[] = 'xml';
        }

        return 'xml';
    }

    /**
     * Generate XML whenever an invoice PDF is created.
     *
     * @param string        $pdf
     * @param OrderDocument $document
     */
    public function maybe_export_invoice_xml( $pdf, $document ): void {
        if ( ! $document instanceof OrderDocument ) {
            return;
        }

        if ( 'invoice' !== $document->get_type() ) {
            return;
        }

        if ( isset( $_REQUEST['action'] ) && 'wpo_wcpdf_preview' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( ! apply_filters( 'wpo_wcpdf_enable_invoice_xml_export', true, $document ) ) {
            return;
        }

        $order = $document->order;

        if ( ! $order instanceof WC_Abstract_Order ) {
            return;
        }

        $this->export_invoice_xml( $document, $order );
    }

    /**
     * Add the XML download action to the order listing table.
     */
    public function add_invoice_xml_listing_action( array $actions, $order ): array {
        if ( ! apply_filters( 'wpo_wcpdf_enable_invoice_xml_listing_action', true, $order ) ) {
            return $actions;
        }

        if ( ! $order instanceof WC_Abstract_Order ) {
            if ( is_numeric( $order ) ) {
                $order = wc_get_order( absint( $order ) );
            } else {
                return $actions;
            }
        }

        if ( ! $order instanceof WC_Abstract_Order ) {
            return $actions;
        }

        if ( ! WPO_WCPDF()->admin->user_can_manage_document( 'invoice' ) ) {
            return $actions;
        }

        $document = wcpdf_get_document( 'invoice', $order );

        if ( ! $document ) {
            return $actions;
        }

        $exists  = $this->invoice_xml_exists( $document );
        $classes = array( $document->get_type(), 'xml', 'wpo-wcpdf' );

        if ( $exists ) {
            $classes[] = 'exists';
        }

        $actions['invoice_xml'] = array(
            'url'           => $this->get_download_url( $document ),
            'img'           => $this->get_icon_url(),
            'alt'           => esc_attr__( 'Invoice XML', 'woocommerce-pdf-invoices-packing-slips' ),
            'exists'        => $exists,
            'printed'       => false,
            'class'         => apply_filters( 'wpo_wcpdf_invoice_xml_action_class', implode( ' ', $classes ), $document, $order ),
            'output_format' => 'xml',
        );

        return $actions;
    }

    /**
     * Handle invoice XML downloads triggered from the order actions.
     */
    public function handle_invoice_xml_download(): void {
        if ( ! check_ajax_referer( 'wpo_wcpdf_download_invoice_xml', 'nonce', false ) ) {
            wp_die( esc_html__( 'Invalid invoice XML request.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        if ( ! WPO_WCPDF()->admin->user_can_manage_document( 'invoice' ) ) {
            wp_die( esc_html__( 'You do not have permission to download invoice XML files.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $order_id <= 0 ) {
            wp_die( esc_html__( 'Missing order reference for invoice XML download.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Abstract_Order ) {
            wp_die( esc_html__( 'Unable to find the requested order for invoice XML download.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $document = wcpdf_get_document( 'invoice', $order );

        if ( ! $document ) {
            wp_die( esc_html__( 'Unable to load the invoice document for XML export.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $force_regeneration = isset( $_GET['regenerate'] ) && wc_string_to_bool( wp_unslash( $_GET['regenerate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $path = $this->export_invoice_xml( $document, $order, $force_regeneration );

        if ( empty( $path ) || ! WPO_WCPDF()->file_system->exists( $path ) ) {
            $path = $this->get_invoice_xml_path( $document, $order );
        }

        if ( empty( $path ) || ! WPO_WCPDF()->file_system->exists( $path ) ) {
            wp_die( esc_html__( 'The invoice XML file could not be generated.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $this->stream_invoice_xml( $path );
    }

    /**
     * Stream XML output for a document (single or bulk).
     *
     * @param OrderDocument|BulkDocument $document
     * @param array                      $order_ids
     */
    public function output_document_xml( $document, array $order_ids = array() ): void {
        if ( ! $document instanceof OrderDocument && ! $document instanceof BulkDocument ) {
            return;
        }

        if ( $document instanceof OrderDocument && 'invoice' !== $document->get_type() ) {
            return;
        }

        if ( $document instanceof BulkDocument && 'invoice' !== $document->get_type() ) {
            return;
        }

        if ( empty( $order_ids ) ) {
            if ( $document instanceof BulkDocument ) {
                $order_ids = (array) $document->order_ids;
            } elseif ( $document instanceof OrderDocument && $document->order instanceof WC_Abstract_Order ) {
                $order_ids = array( $document->order->get_id() );
            }
        }

        $order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );

        if ( empty( $order_ids ) ) {
            wp_die( esc_html__( "You haven't selected any orders", 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $invoices_data = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order instanceof WC_Abstract_Order ) {
                continue;
            }

            $invoice_document = $document instanceof OrderDocument && $document->order instanceof WC_Abstract_Order && $document->order->get_id() === $order_id
                ? $document
                : wcpdf_get_document( 'invoice', $order );

            if ( ! $invoice_document instanceof OrderDocument ) {
                continue;
            }

            if ( ! apply_filters( 'wpo_wcpdf_enable_invoice_xml_export', true, $invoice_document ) ) {
                continue;
            }

            $data = $this->prepare_invoice_data( $invoice_document, $order );

            if ( empty( $data ) ) {
                continue;
            }

            $invoices_data[] = $data;
        }

        $invoices_data = apply_filters( 'wpo_wcpdf_invoice_xml_bulk_export_data', $invoices_data, $document, $order_ids );

        if ( empty( $invoices_data ) ) {
            wp_die( esc_html__( 'No invoice data available for XML export.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $xml = $this->generate_bulk_xml_contents( $invoices_data );
        $xml = apply_filters( 'wpo_wcpdf_invoice_xml_bulk_export_xml', $xml, $invoices_data, $document, $order_ids );
        $xml = $this->normalize_xml_output( $xml );

        if ( empty( $xml ) ) {
            wp_die( esc_html__( 'The invoice XML file could not be generated.', 'woocommerce-pdf-invoices-packing-slips' ) );
        }

        $filename = $this->resolve_bulk_filename( $document, $order_ids );

        $this->output_xml_download( $xml, $filename );
    }

    /**
     * Generate and persist the XML file for an invoice document.
     */
    protected function export_invoice_xml( OrderDocument $document, WC_Abstract_Order $order, bool $force_regeneration = false ) {
        $data = $this->prepare_invoice_data( $document, $order );

        if ( empty( $data ) ) {
            return false;
        }

        $xml = $this->generate_xml_contents( $data );

        if ( empty( $xml ) ) {
            return false;
        }

        $xml = apply_filters( 'wpo_wcpdf_invoice_xml_export_xml', $xml, $data, $document, $order );

        $xml = $this->normalize_xml_output( $xml );

        if ( empty( $xml ) ) {
            return false;
        }

        $path = $this->get_invoice_xml_path( $document, $order, $data );

        if ( empty( $path ) ) {
            return false;
        }

        if ( ! $force_regeneration && WPO_WCPDF()->file_system->exists( $path ) ) {
            $existing = WPO_WCPDF()->file_system->get_contents( $path );
            $cleaned  = $this->normalize_xml_output( $existing );

            if ( ! empty( $cleaned ) && $cleaned !== $existing ) {
                WPO_WCPDF()->file_system->put_contents( $path, $cleaned );
            }

            return $path;
        }

        $directory = dirname( $path );

        if ( ! WPO_WCPDF()->file_system->is_dir( $directory ) ) {
            if ( ! WPO_WCPDF()->file_system->mkdir( $directory ) ) {
                wcpdf_log_error( sprintf( 'Unable to create directory for invoice XML export: %s', $directory ), 'error' );
                return false;
            }
        }

        if ( ! WPO_WCPDF()->file_system->put_contents( $path, $xml ) ) {
            wcpdf_log_error( sprintf( 'Failed to write invoice XML to %s', $path ), 'error' );
            return false;
        }

        do_action( 'wpo_wcpdf_invoice_xml_exported', $path, $data, $document, $order );

        return $path;
    }

    /**
     * Determine if the invoice XML already exists on disk.
     */
    protected function invoice_xml_exists( OrderDocument $document ): bool {
        $path = $this->get_invoice_xml_path( $document, $document->order instanceof WC_Abstract_Order ? $document->order : null );

        if ( empty( $path ) ) {
            return false;
        }

        return WPO_WCPDF()->file_system->exists( $path );
    }

    /**
     * Build the download URL used for the admin order action.
     */
    protected function get_download_url( OrderDocument $document ): string {
        $args = array(
            'action'   => 'wpo_wcpdf_download_invoice_xml',
            'order_id' => $document->order_id,
            'nonce'    => wp_create_nonce( 'wpo_wcpdf_download_invoice_xml' ),
        );

        return apply_filters( 'wpo_wcpdf_invoice_xml_download_url', add_query_arg( $args, admin_url( 'admin-ajax.php' ) ), $document );
    }

    /**
     * Retrieve the icon used for the XML action button.
     */
    protected function get_icon_url(): string {
        $icon = WPO_WCPDF()->plugin_url() . '/assets/images/invoice-xml.svg';

        return apply_filters( 'wpo_wcpdf_invoice_xml_action_icon', $icon );
    }

    /**
     * Resolve the target path for the XML export.
     */
    protected function get_invoice_xml_path( OrderDocument $document, ?WC_Abstract_Order $order = null, array $data = array() ) {
        $order     = $order instanceof WC_Abstract_Order ? $order : ( $document->order instanceof WC_Abstract_Order ? $document->order : null );
        $directory = apply_filters( 'wpo_wcpdf_invoice_xml_export_directory', $this->resolve_export_directory(), $document, $order, $data );

        if ( empty( $directory ) ) {
            return false;
        }

        $directory = trailingslashit( $directory );

        $filename = $this->resolve_filename( $document );
        $filename = apply_filters( 'wpo_wcpdf_invoice_xml_export_filename', $filename, $document, $order, $data );
        $filename = sanitize_file_name( $filename );

        if ( '' === $filename ) {
            return false;
        }

        return $directory . $filename;
    }

    /**
     * Stream the XML file to the browser.
     */
    protected function stream_invoice_xml( string $path ): void {
        $filename = basename( $path );
        $contents = WPO_WCPDF()->file_system->get_contents( $path );

        // Teljes buffer takarítás – ez a kulcs
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // (opcionális) tömörítés kikapcsolása
        @ini_set( 'zlib.output_compression', 'Off' );

        // Biztonsági normalizálás a kiküldés ELŐTT
        $contents = $this->normalize_xml_output( $contents );

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( sprintf( 'Content-Disposition: attachment; filename="%s"', addcslashes( $filename, "\\\"" ) ) );
        header( 'Content-Length: ' . strlen( $contents ) );

        echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Prepare invoice data array and apply filters.
     */
    protected function prepare_invoice_data( OrderDocument $document, WC_Abstract_Order $order ): array {
        $data = $this->collect_invoice_data( $document, $order );

        if ( empty( $data ) ) {
            return array();
        }

        return apply_filters( 'wpo_wcpdf_invoice_xml_export_data', $data, $document, $order );
    }

    /**
     * Ensure XML output starts with a valid declaration and has no leading BOM/whitespace.
     */
    protected function normalize_xml_output( $xml ): string {
        if ( ! is_string( $xml ) ) {
            return '';
        }

        if ( 0 === strpos( $xml, "\xEF\xBB\xBF" ) ) {
            $xml = substr( $xml, 3 );
        }

        if ( '' === $xml ) {
            return '';
        }

        // Remove anything that appears before the first tag to guarantee no blank line precedes the declaration.
        $first_tag_position = strpos( $xml, '<' );

        if ( false === $first_tag_position ) {
            return '';
        }

        if ( $first_tag_position > 0 ) {
            $xml = substr( $xml, $first_tag_position );
        }

        if ( '' === $xml ) {
            return '';
        }

        // Strip any residual ASCII control characters that might still exist before the first tag.
        $xml = ltrim( $xml, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20" );

        if ( '' === $xml ) {
            return '';
        }

        if ( 0 === strpos( $xml, '<?xml' ) ) {
            $xml = preg_replace_callback(
                '/^<\?xml[^>]*\?>\s*/u',
                static function ( $matches ) {
                    return rtrim( $matches[0] ) . "\n";
                },
                $xml,
                1
            );

            if ( ! is_string( $xml ) ) {
                return '';
            }

            return $xml;
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
    }

    /**
     * Build array data used for XML export.
     */
    protected function collect_invoice_data( OrderDocument $document, WC_Abstract_Order $order ): array {
        $invoice_number   = $this->resolve_invoice_number( $document );
        $variable_symbol  = $this->resolve_variable_symbol( $invoice_number );
        $issue_date       = $this->format_date_value( $document->get_date( '', null, 'view', false ), time() );
        $due_date         = $this->format_due_date( $document, $issue_date );
        $tax_date         = $this->format_tax_date( $document, $order, $issue_date );
        $partner_name     = $this->resolve_partner_name( $order );
        $ico              = $this->get_order_meta_value( $order, array( '_billing_ico', 'billing_ico', '_customer_ico' ) );
        $dic              = $this->get_order_meta_value( $order, array( '_billing_dic', 'billing_dic', '_customer_dic' ) );
        $ic_dph           = $this->get_order_meta_value( $order, array( '_billing_ic_dph', 'billing_ic_dph', '_customer_vat', '_billing_vat', '_billing_vat_number', '_billing_tax_id' ) );

        if ( '' === $ic_dph && function_exists( 'wpo_wcpdf_get_order_customer_vat_number' ) ) {
            $vat_number = wpo_wcpdf_get_order_customer_vat_number( $order );
            if ( ! empty( $vat_number ) ) {
                $ic_dph = $vat_number;
            }
        }

        $ico    = '' !== $ico ? $ico : '0';
        $dic    = '' !== $dic ? $dic : '0';
        $ic_dph = '' !== $ic_dph ? $ic_dph : '0';

        $subject = $this->resolve_subject( $document, $order );
        $total   = wc_format_decimal( $order->get_total(), 2 );

        if ( '' === $total ) {
            $total = '0';
        }

        $data = array(
            'InterneCislo'      => $invoice_number,
            'VariabilnySymbol'  => $variable_symbol,
            'Partner'           => array(
                'NazovPartnera' => $partner_name,
                'ICO'           => $ico,
                'DIC'           => $dic,
                'IC_DPH'        => $ic_dph,
            ),
            'Vyhotovene'        => $issue_date,
            'Splatnost'         => $due_date,
            'DVDP'              => $tax_date,
            'PredmetFakturacie' => $subject,
            'SumaNevstupuje'    => $total,
            'KurzPohladavky'    => '1',
            'KurzDPH'           => '1',
            'MenaDokladu'       => $order->get_currency(),
        );

        return array_map( array( $this, 'stringify_value' ), $data );
    }

    /**
     * Resolve formatted invoice number.
     */
    protected function resolve_invoice_number( OrderDocument $document ): string {
        $invoice_number = $document->get_number( '', null, 'view', true );
        $invoice_number = is_string( $invoice_number ) ? trim( $invoice_number ) : '';

        if ( '' === $invoice_number ) {
            $raw_number = $document->get_number();
            if ( is_object( $raw_number ) && is_callable( array( $raw_number, 'get_formatted' ) ) ) {
                $invoice_number = trim( (string) $raw_number->get_formatted() );
            }
        }

        return $invoice_number;
    }

    /**
     * Convert invoice number into a numeric variable symbol.
     */
    protected function resolve_variable_symbol( string $invoice_number ): string {
        $variable_symbol = preg_replace( '/\D+/', '', $invoice_number );

        if ( '' === $variable_symbol ) {
            $variable_symbol = $invoice_number;
        }

        return $variable_symbol;
    }

    /**
     * Convert values to strings while preserving array structure.
     */
    protected function stringify_value( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) {
                $value[ $key ] = $this->stringify_value( $child );
            }

            return $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_scalar( $value ) ) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Create XML string from data.
     */
    protected function generate_xml_contents( array $data ): string {
        $dom       = $this->initialize_dom_document();
        $list_node = $dom->createElement( 'ZoznamPohladavok' );
        $list_node->appendChild( $this->build_invoice_node( $dom, $data ) );
        $dom->documentElement->appendChild( $list_node );

        return $dom->saveXML() ?: '';
    }

    /**
     * Append a text node to the given DOM element.
     */
    protected function append_node_with_value( DOMDocument $dom, \DOMElement $parent, string $name, $value ): void {
        $child = $dom->createElement( $name );
        $child->appendChild( $dom->createTextNode( (string) $value ) );
        $parent->appendChild( $child );
    }

    /**
     * Determine where to store the exported XML file.
     */
    protected function resolve_export_directory() {
        $path = WPO_WCPDF()->main->get_tmp_path( 'attachments' );

        if ( $path ) {
            return $path;
        }

        $uploads = wp_upload_dir();

        return isset( $uploads['basedir'] ) ? $uploads['basedir'] : false;
    }

    /**
     * Create filename for the exported XML.
     */
    protected function resolve_filename( OrderDocument $document ): string {
        $base = $document->get_filename( 'download', array( 'output' => 'ubl' ) );
        $base = preg_replace( '/\.xml$/i', '', $base );

        if ( empty( $base ) ) {
            $base = 'invoice-' . $document->order_id;
        }

        return $base . '.xml';
    }

    /**
     * Resolve the filename for bulk XML downloads.
     */
    protected function resolve_bulk_filename( $document, array $order_ids ): string {
        $filename = '';

        if ( $document instanceof BulkDocument ) {
            $filename = $document->get_filename( 'download', array( 'order_ids' => $order_ids ) );
        } elseif ( $document instanceof OrderDocument ) {
            $filename = $document->get_filename( 'download', array( 'order_ids' => $order_ids ) );
        }

        if ( ! is_string( $filename ) || '' === $filename ) {
            $order_ids = array_values( $order_ids );
            if ( count( $order_ids ) > 1 ) {
                $filename = sprintf( 'invoice-%s-%s.xml', reset( $order_ids ), end( $order_ids ) );
            } else {
                $filename = sprintf( 'invoice-%s.xml', reset( $order_ids ) );
            }
        } else {
            $filename = preg_replace( '/\.(pdf|ubl|html|xml)$/i', '', $filename ) . '.xml';
        }

        return sanitize_file_name( $filename );
    }

    /**
     * Format invoice date values as YYYY-MM-DD.
     */
    protected function format_date_value( $value, int $fallback_timestamp ): string {
        if ( $value instanceof \WC_DateTime ) {
            return $value->date( 'Y-m-d' );
        }

        if ( $value instanceof \DateTimeInterface ) {
            return $value->format( 'Y-m-d' );
        }

        if ( is_numeric( $value ) ) {
            return wp_date( 'Y-m-d', (int) $value );
        }

        if ( is_string( $value ) && '' !== $value ) {
            $timestamp = strtotime( $value );
            if ( false !== $timestamp ) {
                return wp_date( 'Y-m-d', $timestamp );
            }
        }

        return wp_date( 'Y-m-d', $fallback_timestamp );
    }

    /**
     * Format due date using document configuration.
     */
    protected function format_due_date( OrderDocument $document, string $fallback ): string {
        $timestamp = $document->get_due_date();

        if ( $timestamp > 0 ) {
            return wp_date( 'Y-m-d', $timestamp );
        }

        return $fallback;
    }

    /**
     * Determine tax date (DVDP) based on document settings.
     */
    protected function format_tax_date( OrderDocument $document, WC_Abstract_Order $order, string $fallback ): string {
        $display_date_setting = $document->get_display_date();

        if ( 'order_date' === $display_date_setting ) {
            $order_date = $order->get_date_created();
            if ( $order_date instanceof \WC_DateTime ) {
                return $order_date->date( 'Y-m-d' );
            }
            if ( $order_date instanceof \DateTimeInterface ) {
                return $order_date->format( 'Y-m-d' );
            }
        }

        return $fallback;
    }

    /**
     * Attempt to find company name or fallback to customer name.
     */
    protected function resolve_partner_name( WC_Abstract_Order $order ): string {
        $partner_name = $order->get_billing_company();

        if ( empty( $partner_name ) ) {
            $partner_name = trim( $order->get_formatted_billing_full_name() );
        }

        if ( empty( $partner_name ) ) {
            $partner_name = trim( $order->get_formatted_shipping_full_name() );
        }

        if ( empty( $partner_name ) ) {
            $partner_name = __( 'Customer', 'woocommerce-pdf-invoices-packing-slips' );
        }

        return wc_clean( $partner_name );
    }

    /**
     * Fetch the first non-empty meta value from the provided keys.
     */
    protected function get_order_meta_value( WC_Abstract_Order $order, array $keys ): string {
        foreach ( $keys as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) ) {
                return wc_clean( (string) $value );
            }
        }

        return '';
    }

    /**
     * Build invoice subject using purchased items.
     */
    protected function resolve_subject( OrderDocument $document, WC_Abstract_Order $order ): string {
        $items = $document->get_order_items();
        $names = array();

        if ( ! empty( $items ) && is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( isset( $item['name'] ) && '' !== $item['name'] ) {
                    $names[] = wc_clean( wp_strip_all_tags( (string) $item['name'] ) );
                }
            }
        }

        $names = array_filter( array_unique( $names ) );

        if ( ! empty( $names ) ) {
            return implode( ', ', $names );
        }

        return sprintf(
            /* translators: %s: order number */
            __( 'Order %s', 'woocommerce-pdf-invoices-packing-slips' ),
            $order->get_order_number()
        );
    }

    /**
     * Prepare the DOMDocument with the XML declaration and root node.
     */
    protected function initialize_dom_document(): DOMDocument {
        $dom              = new DOMDocument( '1.0', 'UTF-8' );
        $dom->formatOutput = true;

        $root = $dom->createElement( 'Pohladavky' );
        $root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
        $root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema' );
        $dom->appendChild( $root );

        return $dom;
    }

    /**
     * Build a single invoice node.
     */
    protected function build_invoice_node( DOMDocument $dom, array $data ): \DOMElement {
        $invoice_node = $dom->createElement( 'Pohladavka' );

        foreach ( $data as $key => $value ) {
            if ( 'Partner' === $key && is_array( $value ) ) {
                $partner_node = $dom->createElement( 'Partner' );
                foreach ( $value as $partner_key => $partner_value ) {
                    $this->append_node_with_value( $dom, $partner_node, $partner_key, $partner_value );
                }
                $invoice_node->appendChild( $partner_node );
                continue;
            }

            $this->append_node_with_value( $dom, $invoice_node, $key, $value );
        }

        return $invoice_node;
    }

    /**
     * Generate XML for multiple invoices.
     */
    protected function generate_bulk_xml_contents( array $invoices ): string {
        $dom       = $this->initialize_dom_document();
        $list_node = $dom->createElement( 'ZoznamPohladavok' );

        foreach ( $invoices as $invoice_data ) {
            if ( ! is_array( $invoice_data ) || empty( $invoice_data ) ) {
                continue;
            }

            $list_node->appendChild( $this->build_invoice_node( $dom, $invoice_data ) );
        }

        if ( 0 === $list_node->childNodes->length ) {
            return '';
        }

        $dom->documentElement->appendChild( $list_node );

        return $dom->saveXML() ?: '';
    }

    /**
     * Output XML string as a download.
     */
    protected function output_xml_download( string $xml, string $filename ): void {
        if ( '' === $filename ) {
            $filename = 'invoice-export.xml';
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        @ini_set( 'zlib.output_compression', 'Off' );

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( sprintf( 'Content-Disposition: attachment; filename="%s"', addcslashes( $filename, "\\\"" ) ) );
        header( 'Content-Length: ' . strlen( $xml ) );

        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}

endif;
