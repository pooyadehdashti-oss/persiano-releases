<?php
/**
 * Shopping and vendor comparison tools for Batchly.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Purchasing {
    public static function init() {
        add_action( 'admin_post_persiano_hub_print_shopping_plan', array( __CLASS__, 'print_plan' ) );
        add_action( 'admin_post_persiano_hub_export_shopping_plan', array( __CLASS__, 'export_plan_csv' ) );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to use the Persiano purchasing planner.', 'persiano-hub' ) );
        }
    }

    private static function decimal( $value, $default = 0 ) {
        if ( '' === $value || null === $value ) {
            return (float) $default;
        }
        return is_numeric( $value ) ? (float) $value : (float) $default;
    }

    private static function money( $value ) {
        return function_exists( 'wc_price' ) ? wc_price( (float) $value ) : '$' . number_format_i18n( (float) $value, 2 );
    }

    private static function multiplier( $unit ) {
        $map = array(
            'g'    => 1,
            'kg'   => 1000,
            'oz'   => 28.349523125,
            'lb'   => 453.59237,
            'ml'   => 1,
            'l'    => 1000,
            'tsp'  => 5,
            'tbsp' => 15,
            'cup'  => 250,
            'each' => 1,
        );
        return isset( $map[ $unit ] ) ? (float) $map[ $unit ] : 0;
    }


    /**
     * Normalize any supported unit to the base unit used by requirements.
     *
     * @param string $unit Unit key.
     * @return string g, ml, each, or an empty string for unsupported units.
     */
    private static function base_unit_for( $unit ) {
        $unit = sanitize_key( $unit );
        if ( in_array( $unit, array( 'g', 'kg', 'oz', 'lb' ), true ) ) {
            return 'g';
        }
        if ( in_array( $unit, array( 'ml', 'l', 'tsp', 'tbsp', 'cup' ), true ) ) {
            return 'ml';
        }
        if ( 'each' === $unit ) {
            return 'each';
        }
        return '';
    }

    private static function entry_quote( $ingredient_id, $entry ) {
        $vendor = sanitize_text_field( $entry['supplier'] ?? '' );
        $purchase_qty = self::decimal( $entry['purchase_qty'] ?? 0 );
        $purchase_unit = sanitize_key( $entry['purchase_unit'] ?? 'each' );
        $waste_pct = min( 99, max( 0, self::decimal( $entry['waste_pct'] ?? 0 ) ) );
        $subtotal = max( 0, self::decimal( $entry['purchase_cost'] ?? 0 ) );
        $tax = max( 0, self::decimal( $entry['purchase_tax'] ?? 0 ) );
        $gross = isset( $entry['gross_cost'] ) ? max( 0, self::decimal( $entry['gross_cost'] ) ) : $subtotal + $tax;
        $usable_qty = $purchase_qty * self::multiplier( $purchase_unit ) * ( 1 - ( $waste_pct / 100 ) );
        if ( ! $vendor || $usable_qty <= 0 || $gross <= 0 ) {
            return array();
        }
        $time = absint( $entry['time'] ?? 0 );
        return array(
            'ingredient_id' => (int) $ingredient_id,
            'vendor'        => $vendor,
            'brand'         => sanitize_text_field( $entry['brand'] ?? '' ),
            'purchase_qty'  => $purchase_qty,
            'purchase_unit' => $purchase_unit,
            'waste_pct'     => $waste_pct,
            'subtotal_cost' => $subtotal,
            'purchase_tax'  => $tax,
            'gross_cost'    => $gross,
            'usable_qty'    => $usable_qty,
            'unit_cost'     => $gross / $usable_qty,
            'base_unit'     => ( in_array( $purchase_unit, array( 'g','kg','oz','lb' ), true ) ? 'g' : ( in_array( $purchase_unit, array( 'ml','l','tsp','tbsp','cup' ), true ) ? 'ml' : 'each' ) ),
            'source_type'   => sanitize_key( $entry['source_type'] ?? ( ( isset( $entry['source'] ) && false !== strpos( (string) $entry['source'], 'observation' ) ) ? 'observation' : 'purchase' ) ),
            'source'        => sanitize_key( $entry['source'] ?? '' ),
            'valid_until'   => sanitize_text_field( $entry['valid_until'] ?? '' ),
            'time'          => $time,
            'age_days'      => $time ? max( 0, floor( ( time() - $time ) / DAY_IN_SECONDS ) ) : null,
        );
    }

    public static function quotes_for_ingredient( $ingredient_id, $latest_only = true ) {
        $entries = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
        $entries = is_array( $entries ) ? $entries : array();

        // Ensure the current saved purchase is represented even if older data did not create a history row.
        $current_vendor = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_SUPPLIER, true );
        if ( $current_vendor ) {
            $entries[] = array(
                'time'          => 0,
                'purchase_qty'  => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_QTY, true ),
                'purchase_unit' => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true ),
                'purchase_cost' => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_COST, true ),
                'purchase_tax'  => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_TAX, true ),
                'gross_cost'    => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_GROSS_COST, true ),
                'waste_pct'     => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_WASTE_PCT, true ),
                'brand'         => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BRAND, true ),
                'supplier'      => $current_vendor,
                'source_type'   => 'purchase',
                'source'        => 'current_record',
            );
        }

        $quotes = array();
        $latest = array();
        foreach ( $entries as $entry ) {
            $quote = self::entry_quote( $ingredient_id, $entry );
            if ( ! $quote ) {
                continue;
            }
            $quotes[] = $quote;
            $key = strtolower( trim( $quote['vendor'] ) );
            if ( ! isset( $latest[ $key ] ) || (int) $quote['time'] >= (int) $latest[ $key ]['time'] ) {
                $latest[ $key ] = $quote;
            }
        }
        if ( $latest_only ) {
            return array_values( $latest );
        }
        usort( $quotes, function( $a, $b ) { return (int) $b['time'] <=> (int) $a['time']; } );
        return $quotes;
    }

    public static function build_plan( $from, $to ) {
        $requirements = Persiano_Hub_Kitchen::production_requirements( $from, $to );
        return self::build_from_requirements( $requirements, $from, $to );
    }

    public static function build_from_requirements( $requirements, $from = '', $to = '' ) {
        $requirements = is_array( $requirements ) ? $requirements : array( 'recipes'=>array(), 'ingredients'=>array() );
        if ( ! isset( $requirements['ingredients'] ) || ! is_array( $requirements['ingredients'] ) ) { $requirements['ingredients'] = array(); }
        if ( ! isset( $requirements['recipes'] ) || ! is_array( $requirements['recipes'] ) ) { $requirements['recipes'] = array(); }
        $items = array();
        $vendors = array();
        $cheapest_total = 0;
        $preferred_total = 0;

        foreach ( $requirements['ingredients'] as $ingredient_id => $data ) {
            if ( isset( $data['purchasable'] ) && ! $data['purchasable'] ) { continue; }
            if ( 'process' === get_post_meta( $ingredient_id, '_persiano_ingredient_type', true ) ) { continue; }
            $shortage = max( 0, self::decimal( $data['shortage'] ?? 0 ) );
            if ( $shortage <= 0 ) {
                continue;
            }
            $quotes = self::quotes_for_ingredient( $ingredient_id, true );
            $preferred_vendor = trim( (string) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_SUPPLIER, true ) );
            $evaluated = array();
            $requirement_base_unit = self::base_unit_for( $data['base_unit'] ?? '' );
            foreach ( $quotes as $quote ) {
                /* Never divide a mass shortage by a volume package (or vice versa).
                 * Bad unit-family matches previously produced believable-looking but
                 * impossible package counts. */
                if ( ! $requirement_base_unit || $requirement_base_unit !== ( $quote['base_unit'] ?? '' ) ) {
                    continue;
                }
                $packages = (int) ceil( $shortage / max( 0.000001, $quote['usable_qty'] ) );
                $quote['packages'] = max( 1, $packages );
                $quote['estimated_cost'] = $quote['packages'] * $quote['gross_cost'];
                $quote['surplus'] = max( 0, ( $quote['packages'] * $quote['usable_qty'] ) - $shortage );
                $quote['coverage_qty'] = $quote['packages'] * $quote['usable_qty'];
                $quote['overbuy_ratio'] = $shortage > 0 ? $quote['coverage_qty'] / $shortage : 0;
                $evaluated[] = $quote;

                $vendor_key = strtolower( $quote['vendor'] );
                if ( ! isset( $vendors[ $vendor_key ] ) ) {
                    $vendors[ $vendor_key ] = array(
                        'name'      => $quote['vendor'],
                        'total'     => 0,
                        'coverage'  => 0,
                        'missing'   => array(),
                        'max_age'   => 0,
                    );
                }
            }
            usort( $evaluated, function( $a, $b ) { return $a['estimated_cost'] <=> $b['estimated_cost']; } );
            $cheapest = $evaluated ? $evaluated[0] : array();
            $preferred = array();
            foreach ( $evaluated as $quote ) {
                if ( $preferred_vendor && 0 === strcasecmp( $quote['vendor'], $preferred_vendor ) ) {
                    $preferred = $quote;
                    break;
                }
            }
            if ( ! $preferred ) {
                $preferred = $cheapest;
            }
            if ( $cheapest ) { $cheapest_total += $cheapest['estimated_cost']; }
            if ( $preferred ) { $preferred_total += $preferred['estimated_cost']; }

            $items[ $ingredient_id ] = array(
                'name'             => get_the_title( $ingredient_id ),
                'required'         => self::decimal( $data['required'] ?? 0 ),
                'on_hand'          => self::decimal( $data['on_hand'] ?? 0 ),
                'shortage'         => $shortage,
                'base_unit'        => $data['base_unit'] ?? '',
                'preferred_vendor' => $preferred_vendor,
                'quotes'           => $evaluated,
                'cheapest'         => $cheapest,
                'preferred'        => $preferred,
            );
        }

        // Build one-store estimates. A vendor total is meaningful only where it has a quote for every shortage item.
        foreach ( $vendors as $vendor_key => &$vendor ) {
            foreach ( $items as $ingredient_id => $item ) {
                $found = null;
                foreach ( $item['quotes'] as $quote ) {
                    if ( 0 === strcasecmp( $quote['vendor'], $vendor['name'] ) ) {
                        $found = $quote;
                        break;
                    }
                }
                if ( $found ) {
                    $vendor['total'] += $found['estimated_cost'];
                    $vendor['coverage']++;
                    if ( null !== $found['age_days'] ) {
                        $vendor['max_age'] = max( $vendor['max_age'], (int) $found['age_days'] );
                    }
                } else {
                    $vendor['missing'][] = $ingredient_id;
                }
            }
        }
        unset( $vendor );
        uasort( $vendors, function( $a, $b ) {
            $a_complete = empty( $a['missing'] ) ? 0 : 1;
            $b_complete = empty( $b['missing'] ) ? 0 : 1;
            if ( $a_complete !== $b_complete ) { return $a_complete <=> $b_complete; }
            if ( count( $a['missing'] ) !== count( $b['missing'] ) ) { return count( $a['missing'] ) <=> count( $b['missing'] ); }
            return $a['total'] <=> $b['total'];
        } );

        return array(
            'from'            => $from,
            'to'              => $to,
            'requirements'    => $requirements,
            'items'           => $items,
            'vendors'         => $vendors,
            'cheapest_total'  => $cheapest_total,
            'preferred_total' => $preferred_total,
        );
    }

    private static function lines_for_strategy( $plan, $strategy, $vendor_name = '' ) {
        $lines = array();
        foreach ( $plan['items'] as $ingredient_id => $item ) {
            $quote = array();
            if ( 'preferred' === $strategy ) {
                $quote = $item['preferred'];
            } elseif ( 'vendor' === $strategy ) {
                foreach ( $item['quotes'] as $candidate ) {
                    if ( 0 === strcasecmp( $candidate['vendor'], $vendor_name ) ) { $quote = $candidate; break; }
                }
            } else {
                $quote = $item['cheapest'];
            }
            $lines[] = array(
                'ingredient_id' => $ingredient_id,
                'ingredient'    => $item['name'],
                'required'      => $item['shortage'],
                'base_unit'     => $item['base_unit'],
                'quote'         => $quote,
            );
        }
        return $lines;
    }

    private static function grouped_lines( $lines ) {
        $groups = array();
        foreach ( $lines as $line ) {
            $vendor = ! empty( $line['quote']['vendor'] ) ? $line['quote']['vendor'] : __( 'Price unavailable', 'persiano-hub' );
            if ( ! isset( $groups[ $vendor ] ) ) { $groups[ $vendor ] = array(); }
            $groups[ $vendor ][] = $line;
        }
        ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );
        return $groups;
    }


    public static function lines_for_strategy_public( $plan, $strategy, $vendor_name = '' ) {
        return self::lines_for_strategy( $plan, $strategy, $vendor_name );
    }

    public static function grouped_lines_public( $lines ) {
        return self::grouped_lines( $lines );
    }

    private static function strategy_url( $action, $from, $to, $strategy = 'cheapest', $vendor = '' ) {
        $args = array( 'action' => $action, 'from' => $from, 'to' => $to, 'strategy' => $strategy );
        if ( $vendor ) { $args['vendor'] = $vendor; }
        $url = add_query_arg( $args, admin_url( 'admin-post.php' ) );
        return wp_nonce_url( $url, $action . '_' . $from . '_' . $to . '_' . $strategy . '_' . $vendor );
    }

    public static function render_page() {
        self::require_permission();
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $plan = self::build_plan( $from, $to );
        $cheapest_lines = self::lines_for_strategy( $plan, 'cheapest' );
        $groups = self::grouped_lines( $cheapest_lines );
        $print_url = self::strategy_url( 'persiano_hub_print_shopping_plan', $from, $to, 'cheapest' );
        $csv_url = self::strategy_url( 'persiano_hub_export_shopping_plan', $from, $to, 'cheapest' );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Purchase planning', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Shopping & Vendor Comparison', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Turn production shortages into a tax-inclusive shopping estimate using the latest saved price from each vendor. Package sizes are respected, so the cheapest plan is based on what you would actually have to buy.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><a class="button button-primary" target="_blank" href="<?php echo esc_url( $print_url ); ?>"><?php esc_html_e( 'Print cheapest shopping plan', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Export shopping CSV', 'persiano-hub' ); ?></a></div></div>
        <section class="ph-costing-panel"><form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( Persiano_Hub_Costing::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="purchasing"><label><?php esc_html_e( 'From', 'persiano-hub' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label> <label><?php esc_html_e( 'To', 'persiano-hub' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label> <?php submit_button( __( 'Refresh shopping plan', 'persiano-hub' ), 'secondary', '', false ); ?></form></section>
        <div class="ph-costing-stats"><div><strong><?php echo esc_html( count( $plan['items'] ) ); ?></strong><span><?php esc_html_e( 'Items to buy', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( self::money( $plan['cheapest_total'] ) ); ?></strong><span><?php esc_html_e( 'Cheapest mix · tax included', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( self::money( $plan['preferred_total'] ) ); ?></strong><span><?php esc_html_e( 'Preferred-vendor plan', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( count( $plan['vendors'] ) ); ?></strong><span><?php esc_html_e( 'Vendors with saved prices', 'persiano-hub' ); ?></span></div></div>

        <section class="ph-costing-panel"><h2><?php esc_html_e( 'One-store comparison', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Estimates include saved purchase tax. Incomplete stores are shown with the items for which Persiano has no current price.', 'persiano-hub' ); ?></p>
        <?php if ( ! $plan['vendors'] ) : ?><p><?php esc_html_e( 'No vendor price history is available yet. Add vendors to ingredient purchases or scan receipts first.', 'persiano-hub' ); ?></p><?php else : ?><div class="ph-costing-table-wrap"><table class="widefat striped ph-costing-table"><thead><tr><th><?php esc_html_e( 'Vendor', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Estimated checkout', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Coverage', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Oldest price used', 'persiano-hub' ); ?></th><th></th></tr></thead><tbody><?php foreach ( $plan['vendors'] as $vendor ) : $complete = empty( $vendor['missing'] ); $vendor_print = self::strategy_url( 'persiano_hub_print_shopping_plan', $from, $to, 'vendor', $vendor['name'] ); ?><tr><td><strong><?php echo esc_html( $vendor['name'] ); ?></strong></td><td><?php echo wp_kses_post( self::money( $vendor['total'] ) ); ?> <small><?php esc_html_e( 'incl. saved tax', 'persiano-hub' ); ?></small></td><td><?php echo esc_html( $vendor['coverage'] . ' / ' . count( $plan['items'] ) ); ?><?php if ( ! $complete ) : ?><br><span class="description"><?php printf( esc_html__( '%d price(s) missing', 'persiano-hub' ), count( $vendor['missing'] ) ); ?></span><?php endif; ?></td><td><?php echo $vendor['max_age'] ? esc_html( sprintf( _n( '%d day old', '%d days old', $vendor['max_age'], 'persiano-hub' ), $vendor['max_age'] ) ) : '—'; ?></td><td><a class="button button-small" target="_blank" href="<?php echo esc_url( $vendor_print ); ?>"><?php esc_html_e( 'View list', 'persiano-hub' ); ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Cheapest practical mix', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'For each shortage, Persiano chooses the lowest estimated checkout cost after rounding up to whole packages. Taxes saved on the purchase record are included.', 'persiano-hub' ); ?></p>
        <?php if ( ! $groups ) : ?><p><?php esc_html_e( 'No shopping shortages were found for this period.', 'persiano-hub' ); ?></p><?php else : foreach ( $groups as $vendor => $lines ) : ?><h3><?php echo esc_html( $vendor ); ?></h3><div class="ph-costing-table-wrap"><table class="widefat striped ph-costing-table"><thead><tr><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Need', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Buy', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package cost', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Estimated total', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Price age', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $lines as $line ) : $q = $line['quote']; ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( $line['ingredient_id'] ) ); ?>"><?php echo esc_html( $line['ingredient'] ); ?></a></td><td><?php echo esc_html( round( $line['required'], 2 ) . ' ' . $line['base_unit'] ); ?></td><td><?php echo $q ? esc_html( $q['packages'] . ' × ' . $q['purchase_qty'] . ' ' . $q['purchase_unit'] ) : '—'; ?></td><td><?php echo $q ? wp_kses_post( self::money( $q['gross_cost'] ) ) : '—'; ?></td><td><strong><?php echo $q ? wp_kses_post( self::money( $q['estimated_cost'] ) ) : '—'; ?></strong></td><td><?php if ( $q && null !== $q['age_days'] ) : ?><span class="<?php echo $q['age_days'] > 90 ? 'ph-price-stale' : ''; ?>"><?php echo esc_html( $q['age_days'] . 'd' ); ?></span><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endforeach; endif; ?></section>
        <?php
    }

    private static function request_plan_context( $action ) {
        self::require_permission();
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-d' );
        $to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d' );
        $strategy = isset( $_GET['strategy'] ) ? sanitize_key( wp_unslash( $_GET['strategy'] ) ) : 'cheapest';
        $vendor = isset( $_GET['vendor'] ) ? sanitize_text_field( wp_unslash( $_GET['vendor'] ) ) : '';
        check_admin_referer( $action . '_' . $from . '_' . $to . '_' . $strategy . '_' . $vendor );
        $plan = self::build_plan( $from, $to );
        return array( $from, $to, $strategy, $vendor, $plan, self::lines_for_strategy( $plan, $strategy, $vendor ) );
    }

    public static function print_plan() {
        list( $from, $to, $strategy, $vendor, $plan, $lines ) = self::request_plan_context( 'persiano_hub_print_shopping_plan' );
        $groups = self::grouped_lines( $lines );
        $title = 'vendor' === $strategy ? $vendor : ( 'preferred' === $strategy ? __( 'Preferred vendor plan', 'persiano-hub' ) : __( 'Cheapest practical mix', 'persiano-hub' ) );
        $grand = 0.0;
        foreach ( $lines as $line ) {
            $quote = is_array( $line['quote'] ?? null ) ? $line['quote'] : array();
            $grand += max( 0, self::decimal( $quote['estimated_cost'] ?? 0 ) );
        }
        $logo_id    = absint( get_theme_mod( 'custom_logo' ) );
        $logo_url   = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        $printed_at = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $back_url   = add_query_arg(
            array(
                'page' => Persiano_Hub_Costing::MENU_SLUG,
                'tab'  => 'purchasing',
                'from' => $from,
                'to'   => $to,
            ),
            admin_url( 'admin.php' )
        );
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html( $title ); ?></title>
            <style>
                :root{--ink:#28231f;--muted:#736b64;--line:#ddd6cf;--paper:#fff;--wash:#f6f2ed;--brand:#8e2435}
                *{box-sizing:border-box}html{background:#ece8e3}body{margin:0;color:var(--ink);font:14px/1.42 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#ece8e3}
                .toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 18px;background:#241f1b;color:#fff}.toolbar p{margin:0;font-size:12px;opacity:.8}.toolbar-actions{display:flex;gap:8px}.toolbar a,.toolbar button{border:0;border-radius:999px;padding:9px 15px;background:#fff;color:#241f1b;text-decoration:none;font-weight:700;cursor:pointer}.toolbar button{background:var(--brand);color:#fff}
                .sheet{width:min(920px,calc(100% - 32px));margin:24px auto;padding:34px 38px 26px;background:var(--paper);box-shadow:0 8px 32px rgba(35,29,24,.12)}
                .masthead{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding-bottom:20px;border-bottom:3px solid var(--brand)}.brand{display:flex;gap:12px;align-items:center}.brand img{max-width:100px;max-height:52px;width:auto;height:auto}.brand-name{font:700 20px/1.1 Georgia,serif;color:var(--brand)}.eyebrow{margin:0 0 5px;text-transform:uppercase;letter-spacing:.12em;font-size:10px;font-weight:800;color:var(--brand)}h1{margin:0;font:700 31px/1.12 Georgia,serif}.mast-meta{text-align:right;color:var(--muted);font-size:12px}.mast-meta strong{display:block;color:var(--ink);font-size:13px}
                .summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:18px 0 24px}.summary div{padding:11px 12px;background:var(--wash);border:1px solid #eee7e0;border-radius:8px}.summary strong{display:block;font-size:17px;color:var(--brand)}.summary span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}
                .store{margin:0 0 24px;break-inside:auto}.store-heading{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:0;padding:9px 12px;background:var(--ink);color:#fff;break-after:avoid}.store-heading h2{margin:0;font-size:16px}.store-heading strong{font-size:14px}
                table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}th{padding:8px 7px;border-bottom:1px solid var(--line);color:var(--muted);font-size:9px;text-align:left;text-transform:uppercase;letter-spacing:.07em}td{padding:10px 7px;border-bottom:1px solid var(--line);vertical-align:top}tr{break-inside:avoid}.check{width:30px;text-align:center}.item{width:auto}.need{width:105px}.buy{width:145px}.estimate{width:88px;text-align:right}.box{display:inline-block;width:16px;height:16px;border:1.6px solid #39332e;border-radius:3px}.item-name{display:block;font-weight:800}.item-meta{display:block;margin-top:2px;color:var(--muted);font-size:11px}.money{text-align:right;font-weight:800;white-space:nowrap}
                .grand{display:flex;justify-content:flex-end;align-items:baseline;gap:18px;margin-top:8px;padding-top:15px;border-top:3px solid var(--brand);font-size:16px}.grand strong{font-size:24px;color:var(--brand)}.empty{padding:28px;text-align:center;background:var(--wash);color:var(--muted)}.footer{display:flex;justify-content:space-between;gap:12px;margin-top:30px;padding-top:12px;border-top:1px solid var(--line);color:var(--muted);font-size:10px}
                @page{margin:12mm;size:auto}@media print{html,body{background:#fff}.toolbar,.footer{display:none!important}.sheet{width:auto;margin:0;padding:0;box-shadow:none}.store-heading,.summary div{-webkit-print-color-adjust:exact;print-color-adjust:exact}a{color:inherit;text-decoration:none}}@media(max-width:700px){.sheet{width:100%;margin:0;padding:24px 18px}.masthead{display:block}.mast-meta{text-align:left;margin-top:12px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.need{width:84px}.buy{width:112px}.estimate{width:70px}}
            </style>
        </head>
        <body>
            <div class="toolbar"><div><strong><?php esc_html_e( 'Shopping plan print preview', 'persiano-hub' ); ?></strong><p><?php esc_html_e( 'Only the clean plan below will print.', 'persiano-hub' ); ?></p></div><div class="toolbar-actions"><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to purchasing', 'persiano-hub' ); ?></a><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'persiano-hub' ); ?></button></div></div>
            <main class="sheet">
                <header class="masthead"><div><div class="brand"><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt=""><?php endif; ?><div><p class="eyebrow"><?php echo esc_html( sprintf( __( '%s purchasing', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></p><div class="brand-name"><?php echo esc_html( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ); ?></div></div></div><h1><?php echo esc_html( $title ); ?></h1></div><div class="mast-meta"><strong><?php echo esc_html( $from . ' - ' . $to ); ?></strong><?php echo esc_html( sprintf( __( 'Printed %s', 'persiano-hub' ), $printed_at ) ); ?></div></header>
                <div class="summary"><div><strong><?php echo esc_html( count( $lines ) ); ?></strong><span><?php esc_html_e( 'Items', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( count( $groups ) ); ?></strong><span><?php esc_html_e( 'Stores', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( self::money( $grand ) ); ?></strong><span><?php esc_html_e( 'Estimated total', 'persiano-hub' ); ?></span></div><div><strong><?php esc_html_e( 'Tax included', 'persiano-hub' ); ?></strong><span><?php esc_html_e( 'Saved price basis', 'persiano-hub' ); ?></span></div></div>
                <?php if ( ! $groups ) : ?><div class="empty"><?php esc_html_e( 'No shopping shortages were found for this period.', 'persiano-hub' ); ?></div><?php else : foreach ( $groups as $group_vendor => $group_lines ) : $vendor_total = 0.0; foreach ( $group_lines as $line ) { $vendor_total += max( 0, self::decimal( $line['quote']['estimated_cost'] ?? 0 ) ); } ?>
                    <section class="store"><div class="store-heading"><h2><?php echo esc_html( $group_vendor ); ?></h2><strong><?php echo wp_kses_post( self::money( $vendor_total ) ); ?></strong></div><table><thead><tr><th class="check"></th><th class="item"><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th class="need"><?php esc_html_e( 'Need', 'persiano-hub' ); ?></th><th class="buy"><?php esc_html_e( 'Buy', 'persiano-hub' ); ?></th><th class="estimate"><?php esc_html_e( 'Estimate', 'persiano-hub' ); ?></th></tr></thead><tbody>
                    <?php foreach ( $group_lines as $line ) : $q = is_array( $line['quote'] ?? null ) ? $line['quote'] : array(); ?><tr><td class="check"><span class="box"></span></td><td class="item"><span class="item-name"><?php echo esc_html( $line['ingredient'] ); ?></span><?php if ( ! empty( $q['brand'] ) || isset( $q['age_days'] ) ) : ?><span class="item-meta"><?php echo ! empty( $q['brand'] ) ? esc_html( $q['brand'] ) : ''; ?><?php if ( isset( $q['age_days'] ) && null !== $q['age_days'] ) : ?><?php echo ! empty( $q['brand'] ) ? ' · ' : ''; ?><?php echo esc_html( sprintf( __( 'price %sd old', 'persiano-hub' ), $q['age_days'] ) ); ?><?php endif; ?></span><?php endif; ?></td><td class="need"><?php echo esc_html( round( $line['required'], 2 ) . ' ' . $line['base_unit'] ); ?></td><td class="buy"><?php echo $q ? esc_html( $q['packages'] . ' × ' . $q['purchase_qty'] . ' ' . $q['purchase_unit'] ) : esc_html__( 'No saved price', 'persiano-hub' ); ?></td><td class="estimate money"><?php echo $q ? wp_kses_post( self::money( $q['estimated_cost'] ) ) : '—'; ?></td></tr><?php endforeach; ?>
                    </tbody></table></section>
                <?php endforeach; ?><div class="grand"><span><?php esc_html_e( 'Estimated checkout total', 'persiano-hub' ); ?></span><strong><?php echo wp_kses_post( self::money( $grand ) ); ?></strong></div><?php endif; ?>
                <footer class="footer"><span><?php esc_html_e( 'Estimates use saved supplier package prices and purchase taxes.', 'persiano-hub' ); ?></span><span><?php echo esc_html( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ); ?></span></footer>
            </main>
        </body>
        </html><?php
        exit;
    }

    public static function export_plan_csv() {
        list( $from, $to, $strategy, $vendor, $plan, $lines ) = self::request_plan_context( 'persiano_hub_export_shopping_plan' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="persiano-shopping-' . sanitize_file_name( $from . '-' . $to . '-' . $strategy ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Ingredient', 'Vendor', 'Required', 'Base unit', 'Packages', 'Package quantity', 'Package unit', 'Subtotal/package', 'Tax/package', 'Gross/package', 'Estimated tax-included total', 'Price age days' ) );
        foreach ( $lines as $line ) {
            $q = $line['quote'];
            fputcsv( $out, array( $line['ingredient'], $q['vendor'] ?? '', $line['required'], $line['base_unit'], $q['packages'] ?? '', $q['purchase_qty'] ?? '', $q['purchase_unit'] ?? '', $q['subtotal_cost'] ?? '', $q['purchase_tax'] ?? '', $q['gross_cost'] ?? '', $q['estimated_cost'] ?? '', $q['age_days'] ?? '' ) );
        }
        fclose( $out );
        exit;
    }
}
