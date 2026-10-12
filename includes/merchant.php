<?php
/**
 * Merchant listing fields for WooCommerce Product schema.
 *
 * Search Console > Shopping > Merchant listings flags a priced Product whose
 * Offer lacks hasMerchantReturnPolicy, shippingDetails, or a validFrom to pair
 * with the priceValidUntil the SEO plugin already prints, and a Product that
 * carries no global identifier (gtin, mpn, brand). Rank Math and Yoast cannot
 * supply most of these from WooCommerce data alone, so this module completes
 * the Product node at render time, on the same graph filters the invalid
 * Product guard uses:
 *
 *   brand                    first term of the product's brand taxonomy
 *                            (WooCommerce core product_brand; filterable)
 *   offers.validFrom         only when the offer already carries a closing
 *                            date (priceValidUntil / validThrough): the
 *                            scheduled sale start if the product is on sale,
 *                            otherwise the product's last-modified date. The
 *                            current price has been in force at least since
 *                            that save, so it is a truthful start date.
 *   offers.hasMerchantReturnPolicy   from the site-level config
 *   offers.shippingDetails           from the site-level config
 *
 * Nothing is invented: brand is only added when the taxonomy term exists, and
 * the return-policy and shipping nodes are only printed once a real policy has
 * been stored through POST /merchant-policy (or the bulkseme_merchant_policy
 * filter). A property the SEO plugin already set is never overwritten, and a
 * shipping node is only printed when it carries everything Google requires
 * (destination, rate, delivery time), so it can never swap one warning for
 * another.
 *
 * Opt out on a site:
 *   add_filter('bulkseme_merchant_enrichment', '__return_false');
 */

if (!defined('ABSPATH')) exit;

function bulkseme_merchant_enabled() {
    /**
     * Filter: set false to leave WooCommerce Product nodes exactly as the SEO
     * plugin built them.
     *
     * @param bool $enabled Default true.
     */
    return (bool) apply_filters('bulkseme_merchant_enrichment', true);
}

/** Stored config, after the `bulkseme_merchant_policy` filter. Raw, not yet validated. */
function bulkseme_merchant_config() {
    $stored = get_option('bulkseme_merchant_policy', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    /**
     * Filter: supply or override the merchant policy config in code.
     *
     * @param array $config Same shape as the POST /merchant-policy body.
     */
    $config = apply_filters('bulkseme_merchant_policy', $stored);
    return is_array($config) ? $config : [];
}

function bulkseme_merchant_brand_taxonomy() {
    /**
     * Filter: taxonomy the product brand is read from. WooCommerce core ships
     * `product_brand` (9.6+); older brand plugins use `pwb-brand`, `product_brand`
     * or `pa_brand`.
     *
     * @param string $taxonomy Default 'product_brand'.
     */
    $tax = apply_filters('bulkseme_product_brand_taxonomy', 'product_brand');
    return (is_string($tax) && $tax !== '') ? $tax : 'product_brand';
}

function bulkseme_merchant_valid_from_mode() {
    /**
     * Filter: which product date opens the price-validity window when the
     * product is not on a scheduled sale. 'modified' (default), 'created' or
     * 'off' (never add validFrom).
     *
     * @param string $mode
     */
    $mode = apply_filters('bulkseme_offer_valid_from', 'modified');
    return in_array($mode, ['modified', 'created', 'off'], true) ? $mode : 'modified';
}

/**
 * Countries WooCommerce is configured to ship to, when that is an explicit
 * list. Empty when the store ships everywhere (Google needs named countries).
 */
function bulkseme_merchant_default_destinations() {
    $ship_to = get_option('woocommerce_ship_to_countries', '');
    if ($ship_to === 'specific') {
        $list = get_option('woocommerce_specific_ship_to_countries', []);
    } elseif ($ship_to === '' && get_option('woocommerce_allowed_countries', 'all') === 'specific') {
        $list = get_option('woocommerce_specific_allowed_countries', []);
    } else {
        $list = [];
    }
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $code) {
        if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code)) {
            $out[] = $code;
        }
    }
    return array_values(array_unique($out));
}

/** The WC_Product for the page being rendered, or null off product pages. */
function bulkseme_merchant_current_product() {
    if (!function_exists('wc_get_product') || !is_singular('product')) {
        return null;
    }
    $id = get_queried_object_id();
    if (!$id) {
        return null;
    }
    $product = wc_get_product($id);
    return ($product instanceof WC_Product) ? $product : null;
}

/** Brand name from the configured taxonomy, or '' when the product has none. */
function bulkseme_merchant_brand_for_product($product) {
    $tax = bulkseme_merchant_brand_taxonomy();
    if (!taxonomy_exists($tax)) {
        return '';
    }
    $id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($id, $tax);
    if (is_wp_error($terms) || empty($terms)) {
        return '';
    }
    $term = reset($terms);
    return ($term instanceof WP_Term) ? trim(wp_strip_all_tags($term->name)) : '';
}

/** ISO 8601 start of the current price window, or '' when none can be stated. */
function bulkseme_merchant_valid_from($product) {
    $mode = bulkseme_merchant_valid_from_mode();
    if ($mode === 'off') {
        return '';
    }
    $date = null;
    if ($product->is_on_sale() && $product->get_date_on_sale_from()) {
        $date = $product->get_date_on_sale_from();
    } elseif ($mode === 'created') {
        $date = $product->get_date_created();
    } else {
        $date = $product->get_date_modified() ?: $product->get_date_created();
    }
    if (!$date || !method_exists($date, 'date')) {
        return '';
    }
    return $date->date(DATE_ATOM);
}

// ---- config validation -------------------------------------------------------

function bulkseme_merchant_enum($value, $allowed, $field, &$errors) {
    if (!is_string($value) || $value === '') {
        $errors[] = $field . ' must be one of: ' . implode(', ', $allowed);
        return null;
    }
    $bare = preg_replace('#^https?://schema\.org/#', '', trim($value));
    if (!in_array($bare, $allowed, true)) {
        $errors[] = $field . ' must be one of: ' . implode(', ', $allowed);
        return null;
    }
    return 'https://schema.org/' . $bare;
}

function bulkseme_merchant_enum_list($value, $allowed, $field, &$errors) {
    $list = is_array($value) ? $value : [$value];
    $out  = [];
    foreach ($list as $item) {
        $v = bulkseme_merchant_enum($item, $allowed, $field, $errors);
        if ($v !== null) {
            $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

function bulkseme_merchant_country($value, $field, &$errors) {
    if (!is_string($value) || !preg_match('/^[A-Za-z]{2}$/', trim($value))) {
        $errors[] = $field . ' must be a two-letter ISO 3166-1 country code';
        return null;
    }
    return strtoupper(trim($value));
}

function bulkseme_merchant_country_list($value, $field, &$errors, $max = 50) {
    $list = is_array($value) ? $value : [$value];
    if (empty($list) || count($list) > $max) {
        $errors[] = $field . ' must list between 1 and ' . $max . ' country codes';
        return [];
    }
    $out = [];
    foreach ($list as $item) {
        $c = bulkseme_merchant_country($item, $field, $errors);
        if ($c !== null) {
            $out[] = $c;
        }
    }
    return array_values(array_unique($out));
}

function bulkseme_merchant_number($value, $field, &$errors, $min = 0) {
    // is_finite: a number such as 1e400 reads as infinity, which cannot be
    // printed as JSON and would empty the whole schema block of every product.
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > 1000000000) {
        $errors[] = $field . ' must be a number between ' . $min . ' and 1000000000';
        return null;
    }
    return (float) $value == (int) $value ? (int) $value : round((float) $value, 2);
}

function bulkseme_merchant_day_range($value, $field, &$errors) {
    if (!is_array($value)) {
        $errors[] = $field . ' must be an object with min and max (days)';
        return null;
    }
    $min = isset($value['min']) ? bulkseme_merchant_number($value['min'], $field . '.min', $errors) : 0;
    $max = isset($value['max']) ? bulkseme_merchant_number($value['max'], $field . '.max', $errors) : $min;
    if ($min === null || $max === null) {
        return null;
    }
    if ($max < $min) {
        $errors[] = $field . '.max must be >= ' . $field . '.min';
        return null;
    }
    return ['min' => (int) $min, 'max' => (int) $max];
}

function bulkseme_merchant_reject_unknown($obj, $allowed, $field, &$errors) {
    foreach (array_keys($obj) as $k) {
        if (!in_array($k, $allowed, true)) {
            $errors[] = $field . '.' . $k . ' is not a supported key';
        }
    }
}

/**
 * Validate and canonicalise a merchant policy config. Returns
 * ['config' => normalised, 'errors' => [...]]. A part with any error is left
 * out of `config`, so a stored-but-later-broken config degrades to "emit
 * nothing" rather than emitting a half-formed node.
 */
function bulkseme_merchant_normalise_config($raw) {
    $errors = [];
    $out    = [];
    if (!is_array($raw)) {
        return ['config' => [], 'errors' => ['config must be a JSON object']];
    }
    bulkseme_merchant_reject_unknown($raw, ['return_policy', 'shipping'], 'config', $errors);

    // ---- return_policy -> MerchantReturnPolicy --------------------------------
    if (isset($raw['return_policy']) && $raw['return_policy'] !== []) {
        $rp = $raw['return_policy'];
        if (!is_array($rp)) {
            $errors[] = 'return_policy must be an object';
        } else {
            $e = [];
            bulkseme_merchant_reject_unknown($rp, [
                'applicableCountry', 'returnPolicyCategory', 'merchantReturnDays', 'returnMethod',
                'returnFees', 'returnShippingFeesAmount', 'refundType', 'returnPolicyCountry', 'merchantReturnLink',
            ], 'return_policy', $e);
            $n = [];
            $n['applicableCountry']    = bulkseme_merchant_country_list($rp['applicableCountry'] ?? null, 'return_policy.applicableCountry', $e);
            $n['returnPolicyCategory'] = bulkseme_merchant_enum($rp['returnPolicyCategory'] ?? null, [
                'MerchantReturnFiniteReturnWindow', 'MerchantReturnNotPermitted', 'MerchantReturnUnlimitedWindow',
            ], 'return_policy.returnPolicyCategory', $e);
            if ($n['returnPolicyCategory'] === 'https://schema.org/MerchantReturnFiniteReturnWindow') {
                $days = isset($rp['merchantReturnDays']) ? bulkseme_merchant_number($rp['merchantReturnDays'], 'return_policy.merchantReturnDays', $e, 1) : null;
                if ($days === null) {
                    $e[] = 'return_policy.merchantReturnDays (1-3650) is required for MerchantReturnFiniteReturnWindow';
                } elseif ($days > 3650) {
                    $e[] = 'return_policy.merchantReturnDays must be <= 3650';
                } else {
                    $n['merchantReturnDays'] = (int) $days;
                }
            }
            if (isset($rp['returnMethod'])) {
                $n['returnMethod'] = bulkseme_merchant_enum_list($rp['returnMethod'], ['ReturnAtKiosk', 'ReturnByMail', 'ReturnInStore'], 'return_policy.returnMethod', $e);
            }
            if (isset($rp['returnFees'])) {
                $n['returnFees'] = bulkseme_merchant_enum($rp['returnFees'], ['FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees'], 'return_policy.returnFees', $e);
                if ($n['returnFees'] === 'https://schema.org/ReturnShippingFees') {
                    $amt = isset($rp['returnShippingFeesAmount']) ? bulkseme_merchant_number($rp['returnShippingFeesAmount'], 'return_policy.returnShippingFeesAmount', $e) : null;
                    if ($amt === null) {
                        $e[] = 'return_policy.returnShippingFeesAmount is required when returnFees is ReturnShippingFees';
                    } else {
                        $n['returnShippingFeesAmount'] = $amt;
                    }
                }
            } elseif (isset($rp['returnShippingFeesAmount'])) {
                $e[] = 'return_policy.returnShippingFeesAmount needs returnFees = ReturnShippingFees';
            }
            if (isset($rp['refundType'])) {
                $n['refundType'] = bulkseme_merchant_enum_list($rp['refundType'], ['ExchangeRefund', 'FullRefund', 'StoreCreditRefund'], 'return_policy.refundType', $e);
            }
            if (isset($rp['returnPolicyCountry'])) {
                $n['returnPolicyCountry'] = bulkseme_merchant_country($rp['returnPolicyCountry'], 'return_policy.returnPolicyCountry', $e);
            }
            if (isset($rp['merchantReturnLink'])) {
                $url = is_string($rp['merchantReturnLink']) ? esc_url_raw(trim($rp['merchantReturnLink']), ['http', 'https']) : '';
                if ($url === '') {
                    $e[] = 'return_policy.merchantReturnLink must be an http(s) URL';
                } else {
                    $n['merchantReturnLink'] = $url;
                }
            }
            if (empty($e)) {
                $out['return_policy'] = array_filter($n, function ($v) { return $v !== null && $v !== []; });
            }
            $errors = array_merge($errors, $e);
        }
    }

    // ---- shipping -> OfferShippingDetails[] ----------------------------------
    if (isset($raw['shipping']) && $raw['shipping'] !== []) {
        $list = $raw['shipping'];
        if (is_array($list) && (isset($list['shippingRate']) || isset($list['shippingDestination']) || isset($list['handlingTime']) || isset($list['transitTime']))) {
            $list = [$list]; // a single service object
        }
        if (!is_array($list)) {
            $errors[] = 'shipping must be an object or a list of objects';
        } elseif (count($list) > 10) {
            $errors[] = 'shipping supports at most 10 services';
        } else {
            $services = [];
            $e        = [];
            foreach (array_values($list) as $i => $s) {
                $f = 'shipping[' . $i . ']';
                if (!is_array($s)) {
                    $e[] = $f . ' must be an object';
                    continue;
                }
                bulkseme_merchant_reject_unknown($s, ['shippingDestination', 'shippingRate', 'handlingTime', 'transitTime', 'shippingOrigin'], $f, $e);
                $n = [];

                // Destination: list of ISO codes, or objects with regions/postcodes.
                $dest_raw = $s['shippingDestination'] ?? bulkseme_merchant_default_destinations();
                $dests    = [];
                foreach ((is_array($dest_raw) ? $dest_raw : [$dest_raw]) as $d) {
                    if (is_array($d)) {
                        bulkseme_merchant_reject_unknown($d, ['addressCountry', 'addressRegion', 'postalCode'], $f . '.shippingDestination', $e);
                        $c = bulkseme_merchant_country($d['addressCountry'] ?? null, $f . '.shippingDestination.addressCountry', $e);
                        if ($c === null) {
                            continue;
                        }
                        $region = ['addressCountry' => $c];
                        foreach (['addressRegion', 'postalCode'] as $k) {
                            if (isset($d[$k])) {
                                $vals = array_values(array_filter(array_map(function ($v) {
                                    return is_scalar($v) ? trim(wp_strip_all_tags((string) $v)) : '';
                                }, (array) $d[$k]), function ($v) {
                                    return $v !== '';
                                }));
                                if ($vals) {
                                    $region[$k] = $vals;
                                }
                            }
                        }
                        $dests[] = $region;
                    } else {
                        $c = bulkseme_merchant_country($d, $f . '.shippingDestination', $e);
                        if ($c !== null) {
                            $dests[] = ['addressCountry' => $c];
                        }
                    }
                }
                if (empty($dests)) {
                    $e[] = $f . '.shippingDestination is required (WooCommerce ships everywhere, so no default country list exists)';
                }
                $n['shippingDestination'] = $dests;

                // Rate: value and/or maxValue in the offer currency.
                $rate = $s['shippingRate'] ?? null;
                if (is_numeric($rate)) {
                    $rate = ['value' => $rate];
                }
                if (!is_array($rate) || (!isset($rate['value']) && !isset($rate['maxValue']))) {
                    $e[] = $f . '.shippingRate must carry value and/or maxValue';
                } else {
                    bulkseme_merchant_reject_unknown($rate, ['value', 'maxValue'], $f . '.shippingRate', $e);
                    $n['shippingRate'] = [];
                    foreach (['value', 'maxValue'] as $k) {
                        if (isset($rate[$k])) {
                            $v = bulkseme_merchant_number($rate[$k], $f . '.shippingRate.' . $k, $e);
                            if ($v !== null) {
                                $n['shippingRate'][$k] = $v;
                            }
                        }
                    }
                }

                // Delivery time: at least one of handling / transit, in days.
                foreach (['handlingTime', 'transitTime'] as $k) {
                    if (isset($s[$k])) {
                        $r = bulkseme_merchant_day_range($s[$k], $f . '.' . $k, $e);
                        if ($r !== null) {
                            $n[$k] = $r;
                        }
                    }
                }
                if (!isset($n['handlingTime']) && !isset($n['transitTime'])) {
                    $e[] = $f . ' needs handlingTime and/or transitTime ({min, max} in days)';
                }
                if (isset($s['shippingOrigin'])) {
                    $n['shippingOrigin'] = bulkseme_merchant_country($s['shippingOrigin'], $f . '.shippingOrigin', $e);
                }
                $services[] = $n;
            }
            if (empty($e)) {
                $out['shipping'] = $services;
            }
            $errors = array_merge($errors, $e);
        }
    }

    return ['config' => $out, 'errors' => array_values(array_unique($errors))];
}

// ---- node builders -----------------------------------------------------------

function bulkseme_merchant_return_policy_node($rp, $currency) {
    if (empty($rp['applicableCountry']) || empty($rp['returnPolicyCategory'])) {
        return null;
    }
    $node = [
        '@type'                => 'MerchantReturnPolicy',
        'applicableCountry'    => count($rp['applicableCountry']) === 1 ? $rp['applicableCountry'][0] : $rp['applicableCountry'],
        'returnPolicyCategory' => $rp['returnPolicyCategory'],
    ];
    if (isset($rp['merchantReturnDays'])) {
        $node['merchantReturnDays'] = (int) $rp['merchantReturnDays'];
    }
    if (!empty($rp['returnMethod'])) {
        $node['returnMethod'] = count($rp['returnMethod']) === 1 ? $rp['returnMethod'][0] : $rp['returnMethod'];
    }
    if (!empty($rp['returnFees'])) {
        $node['returnFees'] = $rp['returnFees'];
    }
    if (isset($rp['returnShippingFeesAmount']) && $currency !== '') {
        $node['returnShippingFeesAmount'] = ['@type' => 'MonetaryAmount', 'value' => $rp['returnShippingFeesAmount'], 'currency' => $currency];
    }
    if (!empty($rp['refundType'])) {
        $node['refundType'] = count($rp['refundType']) === 1 ? $rp['refundType'][0] : $rp['refundType'];
    }
    if (!empty($rp['returnPolicyCountry'])) {
        $node['returnPolicyCountry'] = $rp['returnPolicyCountry'];
    }
    if (!empty($rp['merchantReturnLink'])) {
        $node['merchantReturnLink'] = $rp['merchantReturnLink'];
    }
    return $node;
}

/** One OfferShippingDetails per configured service; a single node when there is one. */
function bulkseme_merchant_shipping_nodes($services, $currency) {
    if (empty($services) || !is_array($services) || $currency === '') {
        return null;
    }
    $nodes = [];
    foreach ($services as $s) {
        if (empty($s['shippingDestination']) || empty($s['shippingRate']) || (!isset($s['handlingTime']) && !isset($s['transitTime']))) {
            continue; // Google requires all three; never emit a partial node
        }
        $dests = [];
        foreach ($s['shippingDestination'] as $d) {
            $dests[] = array_merge(['@type' => 'DefinedRegion'], $d);
        }
        $rate = array_merge(['@type' => 'MonetaryAmount'], $s['shippingRate'], ['currency' => $currency]);
        $time = ['@type' => 'ShippingDeliveryTime'];
        foreach (['handlingTime', 'transitTime'] as $k) {
            if (isset($s[$k])) {
                $time[$k] = ['@type' => 'QuantitativeValue', 'minValue' => $s[$k]['min'], 'maxValue' => $s[$k]['max'], 'unitCode' => 'DAY'];
            }
        }
        $node = [
            '@type'               => 'OfferShippingDetails',
            'shippingRate'        => $rate,
            'shippingDestination' => count($dests) === 1 ? $dests[0] : $dests,
            'deliveryTime'        => $time,
        ];
        if (!empty($s['shippingOrigin'])) {
            $node['shippingOrigin'] = ['@type' => 'DefinedRegion', 'addressCountry' => $s['shippingOrigin']];
        }
        $nodes[] = $node;
    }
    if (empty($nodes)) {
        return null;
    }
    return count($nodes) === 1 ? $nodes[0] : $nodes;
}

// ---- graph enrichment --------------------------------------------------------

function bulkseme_merchant_enrich_offer($offer, $product, $config) {
    if (!is_array($offer) || !isset($offer['@type'])) {
        return $offer;
    }
    $types = is_array($offer['@type']) ? $offer['@type'] : [$offer['@type']];
    if (!in_array('Offer', $types, true)) {
        return $offer; // AggregateOffer and friends are left alone
    }
    $currency = '';
    if (isset($offer['priceCurrency']) && is_string($offer['priceCurrency'])) {
        $currency = $offer['priceCurrency'];
    } elseif (function_exists('get_woocommerce_currency')) {
        $currency = get_woocommerce_currency();
    }

    if (empty($offer['validFrom']) && $product) {
        $closing = null;
        foreach (['priceValidUntil', 'validThrough'] as $k) {
            if (isset($offer[$k]) && is_string($offer[$k]) && $offer[$k] !== '') {
                $closing = $offer[$k];
                break;
            }
        }
        if ($closing !== null) {
            $from = bulkseme_merchant_valid_from($product);
            $end  = strtotime($closing);
            if ($from !== '' && ($end === false || strtotime($from) <= $end)) {
                $offer['validFrom'] = $from;
            }
        }
    }
    if (empty($offer['hasMerchantReturnPolicy']) && !empty($config['return_policy'])) {
        $node = bulkseme_merchant_return_policy_node($config['return_policy'], $currency);
        if ($node) {
            $offer['hasMerchantReturnPolicy'] = $node;
        }
    }
    if (empty($offer['shippingDetails']) && !empty($config['shipping'])) {
        $node = bulkseme_merchant_shipping_nodes($config['shipping'], $currency);
        if ($node) {
            $offer['shippingDetails'] = $node;
        }
    }
    return $offer;
}

function bulkseme_merchant_enrich_product_node($node, $product, $config) {
    if ($product && empty($node['brand'])) {
        $brand = bulkseme_merchant_brand_for_product($product);
        if ($brand !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
    }
    if (isset($node['offers']) && is_array($node['offers'])) {
        if (isset($node['offers']['@type'])) {
            $node['offers'] = bulkseme_merchant_enrich_offer($node['offers'], $product, $config);
        } else {
            foreach ($node['offers'] as $i => $offer) {
                $node['offers'][$i] = bulkseme_merchant_enrich_offer($offer, $product, $config);
            }
        }
    }
    // Variable products: Rank Math / Yoast nest the sellable variants under a
    // ProductGroup. Each variant carries its own offers, so enrich them too.
    if (isset($node['hasVariant']) && is_array($node['hasVariant'])) {
        foreach ($node['hasVariant'] as $i => $variant) {
            if (is_array($variant) && isset($variant['@type'])) {
                $node['hasVariant'][$i] = bulkseme_merchant_enrich_product_node($variant, $product, $config);
            }
        }
    }
    return $node;
}

/**
 * Enrich every Product / ProductGroup node in a schema graph. Accepts Yoast's
 * numerically indexed graph and Rank Math's keyed one; keys are preserved.
 * `$product` and `$config` default to the current page and stored config and
 * are injectable for tests.
 */
function bulkseme_merchant_enrich_graph($nodes, $product = null, $config = null) {
    if (!is_array($nodes) || empty($nodes) || !bulkseme_merchant_enabled()) {
        return $nodes;
    }
    if (null === $product) {
        $product = bulkseme_merchant_current_product();
    }
    if (!$product) {
        return $nodes; // only WooCommerce product pages carry a sellable Product node
    }
    if (null === $config) {
        $config = bulkseme_merchant_normalise_config(bulkseme_merchant_config())['config'];
    }
    foreach ($nodes as $key => $node) {
        if (!is_array($node) || !isset($node['@type'])) {
            continue;
        }
        $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
        if (in_array('Product', $types, true) || in_array('ProductGroup', $types, true)) {
            $nodes[$key] = bulkseme_merchant_enrich_product_node($node, $product, $config);
        }
    }
    return $nodes;
}

// Runs after the invalid-Product guard (Yoast 20, Rank Math 999) so only nodes
// that survived it are enriched.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own public Schema API filter; we are a consumer of it, not the owner.
add_filter('wpseo_schema_graph', function ($graph) {
    if (!is_array($graph)) {
        return $graph;
    }
    return array_values(bulkseme_merchant_enrich_graph($graph));
}, 25, 1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data) {
    return bulkseme_merchant_enrich_graph($data);
}, 1000, 1);

/** Summary for /status. */
function bulkseme_merchant_status() {
    $norm = bulkseme_merchant_normalise_config(bulkseme_merchant_config());
    $tax  = bulkseme_merchant_brand_taxonomy();
    return [
        'enabled'        => bulkseme_merchant_enabled(),
        'woocommerce'    => function_exists('wc_get_product'),
        'brand_taxonomy' => $tax,
        'brand_taxonomy_exists' => taxonomy_exists($tax),
        'valid_from'     => bulkseme_merchant_valid_from_mode(),
        'return_policy'  => !empty($norm['config']['return_policy']),
        'shipping'       => !empty($norm['config']['shipping']),
        'config_errors'  => count($norm['errors']),
    ];
}

/** Full state for GET /merchant-policy (and the POST response). */
function bulkseme_merchant_describe($preview_id = 0) {
    $raw      = bulkseme_merchant_config();
    $norm     = bulkseme_merchant_normalise_config($raw);
    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
    $out      = [
        'status'               => bulkseme_merchant_status(),
        'currency'             => $currency,
        'default_destinations' => bulkseme_merchant_default_destinations(),
        'config'               => $raw,
        'errors'               => $norm['errors'],
        'nodes'                => [
            'hasMerchantReturnPolicy' => !empty($norm['config']['return_policy']) ? bulkseme_merchant_return_policy_node($norm['config']['return_policy'], $currency) : null,
            'shippingDetails'         => !empty($norm['config']['shipping']) ? bulkseme_merchant_shipping_nodes($norm['config']['shipping'], $currency) : null,
        ],
    ];
    if ($preview_id && function_exists('wc_get_product')) {
        $product = wc_get_product($preview_id);
        if ($product instanceof WC_Product) {
            $out['preview'] = [
                'id'        => (int) $preview_id,
                'brand'     => bulkseme_merchant_brand_for_product($product),
                'validFrom' => bulkseme_merchant_valid_from($product),
                'price'     => $product->get_price(),
            ];
        } else {
            $out['preview'] = ['id' => (int) $preview_id, 'error' => 'not a WooCommerce product'];
        }
    }
    return $out;
}

add_action('rest_api_init', function () {
    $admin = function () { return current_user_can('manage_options'); };

    // -------- /merchant-policy -----------------------------------------------
    // GET  [?id=N]            current config, validation errors, the nodes that
    //                         would be emitted, and (with id) that product's
    //                         brand + validFrom
    // POST {return_policy?, shipping?}   validate + store; REPLACES the stored
    //                         config, so send both parts every time
    // POST {clear: true} / DELETE        remove the stored config
    register_rest_route('seo-meta-bridge/v1', '/merchant-policy', [
        [
            'methods'             => 'GET',
            'permission_callback' => $admin,
            'args'                => ['id' => ['sanitize_callback' => 'absint']],
            'callback'            => function (WP_REST_Request $req) {
                return bulkseme_merchant_describe((int) $req->get_param('id'));
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => $admin,
            'callback'            => function (WP_REST_Request $req) {
                $body = $req->get_json_params();
                if (!is_array($body)) {
                    return new WP_Error('invalid_body', 'send a JSON object: {return_policy, shipping} or {clear: true}', ['status' => 400]);
                }
                if (!empty($body['clear'])) {
                    delete_option('bulkseme_merchant_policy');
                    return array_merge(['cleared' => true], bulkseme_merchant_describe());
                }
                $norm = bulkseme_merchant_normalise_config($body);
                if (!empty($norm['errors'])) {
                    return new WP_Error('invalid_merchant_policy', 'config rejected; nothing stored', ['status' => 400, 'errors' => $norm['errors']]);
                }
                if (empty($norm['config'])) {
                    return new WP_Error('empty_merchant_policy', 'nothing to store: supply return_policy and/or shipping, or {clear: true}', ['status' => 400]);
                }
                update_option('bulkseme_merchant_policy', $norm['config'], false);
                return array_merge(['stored' => true], bulkseme_merchant_describe());
            },
        ],
        [
            'methods'             => 'DELETE',
            'permission_callback' => $admin,
            'callback'            => function () {
                delete_option('bulkseme_merchant_policy');
                return array_merge(['cleared' => true], bulkseme_merchant_describe());
            },
        ],
    ]);
});
