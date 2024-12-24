<?php
/**
 * Plugin Name: WooCommerce Product Importer
 * Description: وارد کردن محصول از دیگر سایتای وردپرسی
 * Version: 1.0.0
 * Author: Mohamad Mehdi Hajati
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add debug logging
function wpi_log($message) {
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}

// Check WooCommerce and PHP requirements
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>WooCommerce Product Importer</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    if (!function_exists('curl_version')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>WooCommerce Product Importer</strong> requires PHP cURL extension.</p></div>';
        });
        return;
    }

    initialize_product_importer_plugin();
});

function initialize_product_importer_plugin() {
    add_action('admin_menu', function () {
        add_menu_page(
            'Product Importer',
            'Product Importer',
            'manage_options',
            'product-importer',
            'render_importer_page',
            'dashicons-download',
            56
        );
    });

    // Add AJAX handler
    add_action('wp_ajax_import_product', 'handle_ajax_import');
}

function handle_ajax_import() {
    check_ajax_referer('import_product_action', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    if (empty($url)) {
        wp_send_json_error('Invalid URL');
        return;
    }

    $result = import_product_from_url($url);
    if (is_wp_error($result)) {
        wpi_log("AJAX Error: " . $result->get_error_message());
        wp_send_json_error($result->get_error_message());
    } else {
        wpi_log("Product successfully imported with ID: " . $result);
        wp_send_json_success(['product_id' => $result]);
    }
}

function render_importer_page() {
    ?>
    <div class="wrap">
        <h1>WooCommerce Product Importer</h1>
        <div id="import-response"></div>
        <form id="product-importer-form">
            <?php wp_nonce_field('import_product_action', 'import_product_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="product_url">Product URL:</label></th>
                    <td>
                        <input type="url" id="product_url" name="product_url" class="regular-text" required>
                        <p class="description">Enter the URL of the product you want to import</p>
                    </td>
                </tr>
            </table>
            <button type="submit" class="button button-primary">Import Product</button>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#product-importer-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitButton = $form.find('button[type="submit"]');
            const $response = $('#import-response');
            
            $submitButton.prop('disabled', true).text('Importing...');
            $response.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_product',
                    url: $('#product_url').val(),
                    nonce: $('#import_product_nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        $response.html(
                            `<div class="notice notice-success"><p>Product imported successfully! ID: ${response.data.product_id}</p></div>`
                        );
                        $form[0].reset();
                    } else {
                        $response.html(
                            `<div class="notice notice-error"><p>Error: ${response.data}</p></div>`
                        );
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error: ", error);
                    $response.html(
                        '<div class="notice notice-error"><p>Server error occurred: ' + error + '</p></div>'
                    );
                },
                complete: function() {
                    $submitButton.prop('disabled', false).text('Import Product');
                }
            });
        });
    });
    </script>
    <?php
}

function import_product_from_url($url) {
    wpi_log("Starting import from URL: " . $url);

    $response = wp_remote_get($url, [
        'timeout' => 30,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    if (is_wp_error($response)) {
        wpi_log("Error fetching URL: " . $response->get_error_message());
        return new WP_Error('fetch_error', 'Failed to fetch the URL: ' . $response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wpi_log("Unexpected response code: " . $status_code);
        return new WP_Error('http_error', 'Unexpected HTTP response code: ' . $status_code);
    }

    $html = wp_remote_retrieve_body($response);

    if (empty($html)) {
        wpi_log("Empty content received from URL");
        return new WP_Error('empty_content', 'No content found at the URL.');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Extract data with updated XPath for mojekooh.com
    $title = get_product_title($xpath);
    $description = get_product_description($xpath);
    $price = get_product_price($xpath);
    $images = get_product_images($xpath);
    $attributes = get_product_attributes($xpath);
    $variations = get_product_variations($xpath);

    if (empty($title)) {
        return new WP_Error('no_title', 'Could not find product title.');
    }

    try {
        $product = create_woo_product([
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'images' => $images,
            'attributes' => $attributes,
            'variations' => $variations
        ]);
        
        wpi_log("Product created successfully with ID: " . $product->get_id());
        return $product->get_id();
    } catch (Exception $e) {
        wpi_log("Error creating product: " . $e->getMessage());
        return new WP_Error('product_creation_failed', $e->getMessage());
    }
}

function get_product_title($xpath) {
    $title_node = $xpath->query("//h1[contains(@class, 'product_title')]");
    if ($title_node->length > 0) {
        return trim($title_node->item(0)->textContent);
    } else {
        error_log('Product title not found');
        wpi_log("HTML content: " . $html);
        return '';
    }
}
function get_product_price($xpath) {
    $price_node = $xpath->query("//p[@class='price']//span[@class='woocommerce-Price-amount amount']");
    if ($price_node->length > 0) {
        return preg_replace('/[^۰-۹0-9.]/u', '', $price_node->item(0)->textContent);
    }
    return '';
}

function get_product_images($xpath) {
    $images = [];
    $image_nodes = $xpath->query("//figure[@class='woocommerce-product-gallery__image']//img/@src");
    foreach ($image_nodes as $img) {
        $images[] = $img->nodeValue;
    }
    return $images;
}

function get_product_description($xpath) {
    $description_node = $xpath->query("//div[@id='tab-description']");
    return $description_node->length > 0 ? trim($description_node->item(0)->textContent) : '';
}

function get_product_attributes($xpath) {
    $attributes = [];
    $attribute_rows = $xpath->query("//table[@class='woocommerce-product-attributes shop_attributes']//tr");
    foreach ($attribute_rows as $row) {
        $name = $xpath->query("./th", $row)->item(0)->textContent ?? '';
        $value = $xpath->query("./td", $row)->item(0)->textContent ?? '';
        if ($name && $value) {
            $attributes[$name] = trim($value);
        }
    }
    return $attributes;
}

function get_product_variations($xpath) {
    $variations = [];
    $variation_nodes = $xpath->query("//form[@class='variations_form cart']//select[@class='product-variations']/option");
    foreach ($variation_nodes as $node) {
        $value = $node->getAttribute('value');
        $text = trim($node->textContent);
        if ($value && $text) {
            $variations[] = [
                'value' => $value,
                'text' => $text,
            ];
        }
    }
    return $variations;
}

function create_woo_product($data) {
    if (!empty($data['variations'])) {
        $product = new WC_Product_Variable();

        $attributes = [];
        foreach ($data['attributes'] as $attr_name => $attr_values) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_name);
            $attribute->set_options($attr_values);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }

        $product->set_attributes($attributes);

        $product->set_name($data['title']);

        $product->set_description($data['description']);
        $product->set_price($data['price']);
        $product->save();

        foreach ($data['images'] as $image_url) {
            $attachment_id = media_sideload_image($image_url, $product->get_id(), '', 'id');
            if (!is_wp_error($attachment_id)) {
                $product->set_image_id($attachment_id);
            }
        }

        return $product;
    } else {
        $product = new WC_Product_Simple();
        $product->set_name($data['title']);
        $product->set_description($data['description']);
        $product->set_price($data['price']);
        $product->save();

        foreach ($data['images'] as $image_url) {
            $attachment_id = media_sideload_image($image_url, $product->get_id(), '', 'id');
            if (!is_wp_error($attachment_id)) {
                $product->set_image_id($attachment_id);
            }
        }

        return $product;
    }
}