<?php
/**
 * Plugin Name: Sri Lanka Bike Rental - Location & Distance Calculator
 * Plugin URI: https://example.com/bike-rental-location-calculator
 * Description: Adds custom pickup and dropoff location fields with distance calculation and fee assessment for bike rentals.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: slbr-locations
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 7.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Define plugin constants
define('SLBR_LOCATIONS_VERSION', '1.0.0');
define('SLBR_LOCATIONS_PATH', plugin_dir_path(__FILE__));
define('SLBR_LOCATIONS_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class SLBR_Locations {
    /**
     * Constructor
     */
    public function __construct() {
        // Add custom fields to checkout
        add_filter('woocommerce_checkout_fields', array($this, 'add_custom_pickup_dropoff_location_fields'));
        
        // Add office coordinates to head
        add_action('wp_head', array($this, 'add_office_coordinates'));
        
        // Add Google Maps script and styles
        add_action('wp_footer', array($this, 'add_google_maps_script'));
        
        // Display locations in checkout review
        add_action('woocommerce_checkout_order_review', array($this, 'display_locations_in_checkout_review'), 20);
        
        // Add the distance fee to shipping cost
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_distance_shipping_fee'));
        
        // Save fee to session
        add_action('woocommerce_checkout_update_order_review', array($this, 'save_distance_fee_to_session'));
        
        // Save location data to order
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_custom_location_data'));
        
        // Display locations in admin order
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_custom_location_data_in_admin'));
        
        // Display locations in order emails
        add_action('woocommerce_email_order_details', array($this, 'add_location_details_to_emails'), 20, 4);
        
        // Display locations on thank you page
        add_action('woocommerce_thankyou', array($this, 'display_locations_on_thankyou_page'), 10);
        
        // Display locations in account orders
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_locations_in_account_orders'), 10, 1);
        
        // Add checkout notice
        add_action('woocommerce_before_checkout_form', array($this, 'add_distance_fee_notice'), 5);
        
        // Make sure fee persists
        add_action('woocommerce_checkout_create_order', array($this, 'add_distance_fee_to_order'), 10, 2);
        
        // Ensure fee in cart
        add_action('woocommerce_before_calculate_totals', array($this, 'ensure_distance_fee_in_cart'), 10, 1);
        
        // Store fee before payment
        add_action('woocommerce_before_pay_action', array($this, 'store_distance_fee_before_payment'), 10, 1);
        
        // Ensure fee is in order totals
        add_filter('woocommerce_get_order_item_totals', array($this, 'add_distance_fee_to_order_totals'), 10, 3);
        
        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Add custom fields to the checkout page
     */
    public function add_custom_pickup_dropoff_location_fields($fields) {
        // Pickup Location
        $fields['shipping']['shipping_pickup_location'] = array(
            'label' => __('Pickup Location', 'slbr-locations'),
            'type' => 'select',
            'options' => array(
                'negombo_office' => __('Sri Lanka Bike Rent Negombo Office', 'slbr-locations'),
                'custom' => __('Enter Custom Pickup Location', 'slbr-locations'),
            ),
            'required' => true,
            'class' => array('form-row-wide'),
            'priority' => 10,
        );

        // Drop-off Location
        $fields['shipping']['shipping_dropoff_location'] = array(
            'label' => __('Drop-off Location', 'slbr-locations'),
            'type' => 'select',
            'options' => array(
                'negombo_office' => __('Sri Lanka Bike Rent Negombo Office', 'slbr-locations'),
                'custom' => __('Enter Custom Drop-off Location', 'slbr-locations'),
            ),
            'required' => true,
            'class' => array('form-row-wide'),
            'priority' => 20,
        );

        // Custom Pickup Location Text Field (Hidden Initially)
        $fields['shipping']['shipping_custom_pickup_location'] = array(
            'label' => __('Enter Custom Pickup Location', 'slbr-locations'),
            'placeholder' => __('Enter address or place name', 'slbr-locations'),
            'required' => false,
            'class' => array('form-row-wide', 'custom-location-field'),
            'priority' => 15,
        );

        // Custom Drop-off Location Text Field (Hidden Initially)
        $fields['shipping']['shipping_custom_dropoff_location'] = array(
            'label' => __('Enter Custom Drop-off Location', 'slbr-locations'),
            'placeholder' => __('Enter address or place name', 'slbr-locations'),
            'required' => false,
            'class' => array('form-row-wide', 'custom-location-field'),
            'priority' => 25,
        );

        // Hidden fields to store latitude and longitude
        $fields['shipping']['shipping_pickup_lat'] = array(
            'type' => 'hidden',
            'required' => false,
        );
        
        $fields['shipping']['shipping_pickup_lng'] = array(
            'type' => 'hidden',
            'required' => false,
        );
        
        $fields['shipping']['shipping_dropoff_lat'] = array(
            'type' => 'hidden',
            'required' => false,
        );
        
        $fields['shipping']['shipping_dropoff_lng'] = array(
            'type' => 'hidden',
            'required' => false,
        );

        // Hidden fields for distance calculation
        $fields['shipping']['shipping_pickup_distance'] = array(
            'type' => 'hidden',
            'required' => false,
        );
        
        $fields['shipping']['shipping_dropoff_distance'] = array(
            'type' => 'hidden',
            'required' => false,
        );
        
        $fields['shipping']['shipping_distance_fee'] = array(
            'type' => 'hidden',
            'required' => false,
        );

        return $fields;
    }

    /**
     * Define the office coordinates
     */
    public function add_office_coordinates() {
        if (is_checkout()) {
            $options = get_option('slbr_locations_settings');
            $lat = isset($options['office_lat']) ? floatval($options['office_lat']) : 7.2095;
            $lng = isset($options['office_lng']) ? floatval($options['office_lng']) : 79.8384;
            ?>
            <script>
            var negomboOfficeCoordinates = {
                lat: <?php echo $lat; ?>,  // Latitude for Negombo office
                lng: <?php echo $lng; ?>   // Longitude for Negombo office
            };
            </script>
            <?php
        }
    }

    /**
     * Add Google Maps script and custom styling
     */
    public function add_google_maps_script() {
        if (is_checkout()) {
            $options = get_option('slbr_locations_settings');
            $api_key = isset($options['google_maps_api_key']) ? esc_attr($options['google_maps_api_key']) : 'AIzaSyCMJVqmYfuewn4GoTyu341nRmPeA4Yf01A';
            $rate_per_km = isset($options['rate_per_km']) ? floatval($options['rate_per_km']) : 0.35;
            $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
            ?>
            <style>
                .map-container {
                    height: 300px;
                    margin-top: 10px;
                    margin-bottom: 20px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                }
                .location-note {
                    font-size: 12px;
                    color: #777;
                    margin-top: 5px;
                }
                .distance-info {
                    background-color: #f8f8f8;
                    padding: 8px 12px;
                    border-radius: 4px;
                    border-left: 3px solid #2271b1;
                    margin-bottom: 10px;
                }
                .pickup-dropoff-review {
                    margin-top: 20px;
                    padding: 15px;
                    background-color: #f8f8f8;
                    border-radius: 4px;
                    border-left: 3px solid #2271b1;
                }
            </style>
            <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo $api_key; ?>&libraries=places"></script>
            <script>
            jQuery(document).ready(function($) {
                // Completely hide the custom fields initially
                $("#shipping_custom_pickup_location_field").css("display", "none");
                $("#shipping_custom_dropoff_location_field").css("display", "none");
                
                var pickupMap, dropoffMap;
                var pickupMarker, dropoffMarker;
                
                // Define cost per kilometer - rate per km
                var costPerKm = <?php echo $rate_per_km; ?>;
                var currency = '<?php echo $currency; ?>';
                var currencySymbol = currency === 'USD' ? '$' : (currency === 'EUR' ? '€' : currency);
                
                // Distance matrix service
                var distanceService = new google.maps.DistanceMatrixService();
                
                // Function to calculate distance and update shipping
                function calculateDistance(origin, destination, type) {
                    if (!origin || !destination) {
                        return;
                    }
                    
                    distanceService.getDistanceMatrix({
                        origins: [origin],
                        destinations: [destination],
                        travelMode: google.maps.TravelMode.DRIVING,
                        unitSystem: google.maps.UnitSystem.METRIC
                    }, function(response, status) {
                        if (status === 'OK' && response.rows[0].elements[0].status === 'OK') {
                            var distanceValue = response.rows[0].elements[0].distance.value; // in meters
                            var distanceKm = distanceValue / 1000; // convert to km
                            
                            // Round to 1 decimal place
                            distanceKm = Math.round(distanceKm * 10) / 10;
                            
                            // Store the distance in the hidden field
                            if (type === 'pickup') {
                                $('#shipping_pickup_distance').val(distanceKm);
                            } else {
                                $('#shipping_dropoff_distance').val(distanceKm);
                            }
                            
                            // Calculate the total distance fee
                            updateTotalDistanceFee();
                            
                            // Show the distance information near the field
                            updateDistanceDisplay(type, distanceKm);
                        }
                    });
                }
                
                // Function to update the total distance fee
                function updateTotalDistanceFee() {
                    var pickupDistance = parseFloat($('#shipping_pickup_distance').val()) || 0;
                    var dropoffDistance = parseFloat($('#shipping_dropoff_distance').val()) || 0;
                    
                    // Calculate total fee (only if custom locations are selected)
                    var totalFee = 0;
                    
                    if ($('#shipping_pickup_location').val() === 'custom') {
                        totalFee += pickupDistance * costPerKm;
                    }
                    
                    if ($('#shipping_dropoff_location').val() === 'custom') {
                        totalFee += dropoffDistance * costPerKm;
                    }
                    
                    // Round to 2 decimal places for currency
                    totalFee = Math.round(totalFee * 100) / 100;
                    
                    // Store the fee
                    $('#shipping_distance_fee').val(totalFee);
                    
                    // Trigger update of checkout to refresh shipping costs
                    $('body').trigger('update_checkout');
                    
                    // Update the location review
                    updateLocationReview();
                }
                
                // Function to display distance information
                function updateDistanceDisplay(type, distance) {
                    var fieldSelector = type === 'pickup' ? '#shipping_custom_pickup_location_field' : '#shipping_custom_dropoff_location_field';
                    var displayId = type + '-distance-info';
                    
                    // Remove existing display if present
                    $('#' + displayId).remove();
                    
                    // Create distance info display
                    var fee = (distance * costPerKm).toFixed(2);
                    var html = '<div id="' + displayId + '" class="distance-info">';
                    html += 'Distance from office: <strong>' + distance + ' km</strong><br>';
                    html += 'Additional fee: <strong>' + currencySymbol + fee + ' ' + currency + '</strong>';
                    html += '</div>';
                    
                    $(fieldSelector).append(html);
                }
                
                function setupMapForField(fieldType) {
                    var inputField = fieldType === 'pickup' ? 'shipping_custom_pickup_location' : 'shipping_custom_dropoff_location';
                    var containerID = fieldType + '-map-container';
                    var noteID = fieldType + '-location-note';
                    
                    // Add help note if it doesn't exist
                    if ($('#' + noteID).length === 0) {
                        $('#' + inputField + '_field').append('<div id="' + noteID + '" class="location-note">Type an address above or select a location on the map below</div>');
                    }
                    
                    // Create map container if it doesn't exist
                    if ($('#' + containerID).length === 0) {
                        $('#' + inputField + '_field').append('<div id="' + containerID + '" class="map-container"></div>');
                    }
                    
                    // Initialize the map
                    var mapOptions = {
                        center: {lat: 7.2906, lng: 80.6337}, // Default to center of Sri Lanka
                        zoom: 10,
                        mapTypeControl: true,
                        streetViewControl: false
                    };
                    
                    var map = new google.maps.Map(document.getElementById(containerID), mapOptions);
                    var marker = new google.maps.Marker({
                        map: map,
                        draggable: true,
                        position: mapOptions.center
                    });
                    
                    // Store map and marker references
                    if (fieldType === 'pickup') {
                        pickupMap = map;
                        pickupMarker = marker;
                    } else {
                        dropoffMap = map;
                        dropoffMarker = marker;
                    }
                    
                    // Setup autocomplete
                    var input = document.getElementById(inputField);
                    var autocomplete = new google.maps.places.Autocomplete(input);
                    autocomplete.bindTo('bounds', map);
                    
                    // Listen for place selection
                    autocomplete.addListener('place_changed', function() {
                        var place = autocomplete.getPlace();
                        
                        if (!place.geometry) {
                            // If no place geometry, just keep the manually entered address
                            var address = $('#' + inputField).val();
                            if (fieldType === 'pickup') {
                                $('#shipping_pickup_lat').val('');
                                $('#shipping_pickup_lng').val('');
                            } else {
                                $('#shipping_dropoff_lat').val('');
                                $('#shipping_dropoff_lng').val('');
                            }
                            return;
                        }
                        
                        // Set map center and zoom
                        if (place.geometry.viewport) {
                            map.fitBounds(place.geometry.viewport);
                        } else {
                            map.setCenter(place.geometry.location);
                            map.setZoom(17);
                        }
                        
                        // Set marker position
                        marker.setPosition(place.geometry.location);
                        
                        // Update hidden fields
                        if (fieldType === 'pickup') {
                            $('#shipping_pickup_lat').val(place.geometry.location.lat());
                            $('#shipping_pickup_lng').val(place.geometry.location.lng());
                        } else {
                            $('#shipping_dropoff_lat').val(place.geometry.location.lat());
                            $('#shipping_dropoff_lng').val(place.geometry.location.lng());
                        }
                        
                        // Calculate distance
                        calculateDistance(
                            negomboOfficeCoordinates,
                            place.geometry.location,
                            fieldType
                        );
                    });
                    
                    // Update hidden fields when marker is dragged
                    marker.addListener('dragend', function() {
                        var position = marker.getPosition();
                        
                        if (fieldType === 'pickup') {
                            $('#shipping_pickup_lat').val(position.lat());
                            $('#shipping_pickup_lng').val(position.lng());
                        } else {
                            $('#shipping_dropoff_lat').val(position.lat());
                            $('#shipping_dropoff_lng').val(position.lng());
                        }
                        
                        // Get address from coordinates (reverse geocoding)
                        var geocoder = new google.maps.Geocoder();
                        geocoder.geocode({'location': position}, function(results, status) {
                            if (status === 'OK' && results[0]) {
                                $('#' + inputField).val(results[0].formatted_address);
                            }
                        });
                        
                        // Calculate distance
                        calculateDistance(
                            negomboOfficeCoordinates,
                            position,
                            fieldType
                        );
                    });
                    
                    // Update map when clicking on it
                    map.addListener('click', function(event) {
                        marker.setPosition(event.latLng);
                        
                        // Trigger dragend event to update fields
                        google.maps.event.trigger(marker, 'dragend');
                    });
                    
                    // Manual input handling - if user types in field directly
                    $('#' + inputField).on('blur', function() {
                        var enteredText = $(this).val();
                        if (enteredText && !$(this).data('selected-from-dropdown')) {
                            // Store the manual entry, coordinates will be empty if not selected from map
                            if (fieldType === 'pickup') {
                                // Keep the text but clear coordinates if manually entered
                                if (!$('#shipping_pickup_lat').val() || !$('#shipping_pickup_lng').val()) {
                                    // Only clear if coordinates weren't set by map selection
                                    $('#shipping_pickup_lat').val('');
                                    $('#shipping_pickup_lng').val('');
                                }
                            } else {
                                if (!$('#shipping_dropoff_lat').val() || !$('#shipping_dropoff_lng').val()) {
                                    $('#shipping_dropoff_lat').val('');
                                    $('#shipping_dropoff_lng').val('');
                                }
                            }
                        }
                    });
                    
                    // Reset the selected flag when user starts typing
                    $('#' + inputField).on('input', function() {
                        $(this).data('selected-from-dropdown', false);
                    });
                    
                    // Set the flag when an item is selected from dropdown
                    google.maps.event.addListener(autocomplete, 'place_changed', function() {
                        $('#' + inputField).data('selected-from-dropdown', true);
                    });
                }
                
                // Update location display in review section
                function updateLocationReview() {
                    var pickupLocation = $('#shipping_pickup_location').val();
                    var dropoffLocation = $('#shipping_dropoff_location').val();
                    
                    // Set pickup location text
                    if (pickupLocation === 'negombo_office') {
                        $('#review-pickup-location-text').text('Sri Lanka Bike Rent Negombo Office');
                    } else if (pickupLocation === 'custom') {
                        var customPickup = $('#shipping_custom_pickup_location').val();
                        $('#review-pickup-location-text').text(customPickup || 'Custom location (not specified)');
                    }
                    
                    // Set dropoff location text
                    if (dropoffLocation === 'negombo_office') {
                        $('#review-dropoff-location-text').text('Sri Lanka Bike Rent Negombo Office');
                    } else if (dropoffLocation === 'custom') {
                        var customDropoff = $('#shipping_custom_dropoff_location').val();
                        $('#review-dropoff-location-text').text(customDropoff || 'Custom location (not specified)');
                    }
                    
                    // Show distance fee if applicable
                    var distanceFee = parseFloat($('#shipping_distance_fee').val()) || 0;
                    if (distanceFee > 0) {
                        if ($('#distance-fee-review').length === 0) {
                            $('#pickup-dropoff-locations-review').append('<div id="distance-fee-review" style="margin-top: 10px;"><strong>Distance Fee:</strong> <span id="review-distance-fee"></span> ' + currency + '</div>');
                        }
                        $('#review-distance-fee').text(currencySymbol + distanceFee.toFixed(2));
                    } else {
                        $('#distance-fee-review').remove();
                    }
                }
                
                // Handle changes to the pickup location dropdown
                $("#shipping_pickup_location").on("change", function() {
                    if ($(this).val() === "custom") {
                        $("#shipping_custom_pickup_location_field").css("display", "block");
                        setTimeout(function() {
                            setupMapForField('pickup');
                        }, 100);
                    } else {
                        $("#shipping_custom_pickup_location_field").css("display", "none");
                        $('#shipping_pickup_distance').val(0);
                        updateTotalDistanceFee();
                    }
                    updateLocationReview();
                });
                
                // Handle changes to the dropoff location dropdown
                $("#shipping_dropoff_location").on("change", function() {
                    if ($(this).val() === "custom") {
                        $("#shipping_custom_dropoff_location_field").css("display", "block");
                        setTimeout(function() {
                            setupMapForField('dropoff');
                        }, 100);
                    } else {
                        $("#shipping_custom_dropoff_location_field").css("display", "none");
                        $('#shipping_dropoff_distance').val(0);
                        updateTotalDistanceFee();
                    }
                    updateLocationReview();
                });
                
                // Check initial values and set visibility
                if ($("#shipping_pickup_location").val() === "custom") {
                    $("#shipping_custom_pickup_location_field").css("display", "block");
                    setTimeout(function() {
                        setupMapForField('pickup');
                    }, 100);
                }
                
                if ($("#shipping_dropoff_location").val() === "custom") {
                    $("#shipping_custom_dropoff_location_field").css("display", "block");
                    setTimeout(function() {
                        setupMapForField('dropoff');
                    }, 100);
                }
                
                // Update when custom location fields change
                $('#shipping_custom_pickup_location, #shipping_custom_dropoff_location').on('change keyup', function() {
                    setTimeout(function() {
                        updateLocationReview();
                    }, 500);
                });
                
                // Trigger initial review update
                setTimeout(function() {
                    updateLocationReview();
                }, 300);
                
                // Update when checkout is updated
                $(document.body).on('updated_checkout', function() {
                    updateLocationReview();
                });
            });
            </script>
            <?php
        }
    }

    /**
     * Display pickup and drop-off locations in the order review section
     */
    public function display_locations_in_checkout_review() {
        ?>
        <div id="pickup-dropoff-locations-review" class="pickup-dropoff-review">
            <h3 style="margin-top: 0;"><?php _e('Pickup & Drop-off Details', 'slbr-locations'); ?></h3>
            <div id="pickup-location-review">
                <strong><?php _e('Pickup Location:', 'slbr-locations'); ?></strong>
                <span id="review-pickup-location-text"></span>
            </div>
            <div id="dropoff-location-review" style="margin-top: 10px;">
                <strong><?php _e('Drop-off Location:', 'slbr-locations'); ?></strong>
                <span id="review-dropoff-location-text"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Add the distance fee to the shipping cost
     */
    public function add_distance_shipping_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // For checkout page
        if (is_checkout() && isset($_POST['post_data'])) {
            // Parse the post data
            parse_str($_POST['post_data'], $post_data);
            
            // Check if distance fee exists and is greater than zero
            if (isset($post_data['shipping_distance_fee']) && $post_data['shipping_distance_fee'] > 0) {
                $distance_fee = floatval($post_data['shipping_distance_fee']);
                
                if ($distance_fee > 0) {
                    $cart->add_fee('Distance Fee', $distance_fee);
                }
            }
        } 
        // For thank you page and other pages (using session)
        else if (WC()->session && WC()->session->get('distance_fee')) {
            $distance_fee = floatval(WC()->session->get('distance_fee'));
            if ($distance_fee > 0) {
                $cart->add_fee('Distance Fee', $distance_fee);
            }
        }
    }

    /**
     * Save the distance fee to session before payment processing
     */
    public function save_distance_fee_to_session($post_data) {
        parse_str($post_data, $posted_data);
        
        if (!empty($posted_data['shipping_distance_fee'])) {
            $distance_fee = floatval($posted_data['shipping_distance_fee']);
            WC()->session->set('distance_fee', $distance_fee);
        }
    }

    /**
     * Save the custom location data to the order
     */
    public function save_custom_location_data($order_id) {
        // Save pickup information
        if (isset($_POST['shipping_pickup_location'])) {
            if ($_POST['shipping_pickup_location'] === 'negombo_office') {
                update_post_meta($order_id, '_shipping_pickup_location', 'Negombo Office');
            } else if ($_POST['shipping_pickup_location'] === 'custom' && !empty($_POST['shipping_custom_pickup_location'])) {
                update_post_meta($order_id, '_shipping_pickup_location', 'Custom');
                update_post_meta($order_id, '_shipping_custom_pickup_location', sanitize_text_field($_POST['shipping_custom_pickup_location']));
            }
        }
        
        // Save dropoff information
        if (isset($_POST['shipping_dropoff_location'])) {
            if ($_POST['shipping_dropoff_location'] === 'negombo_office') {
                update_post_meta($order_id, '_shipping_dropoff_location', 'Negombo Office');
            } else if ($_POST['shipping_dropoff_location'] === 'custom' && !empty($_POST['shipping_custom_dropoff_location'])) {
                update_post_meta($order_id, '_shipping_dropoff_location', 'Custom');
                update_post_meta($order_id, '_shipping_custom_dropoff_location', sanitize_text_field($_POST['shipping_custom_dropoff_location']));
            }
        }
        
        // Save distance data
        if (!empty($_POST['shipping_dropoff_lat']) && !empty($_POST['shipping_dropoff_lng'])) {
            update_post_meta($order_id, '_shipping_dropoff_lat', sanitize_text_field($_POST['shipping_dropoff_lat']));
            update_post_meta($order_id, '_shipping_dropoff_lng', sanitize_text_field($_POST['shipping_dropoff_lng']));
        }
        
        // Clear the session data after order is created
        if (WC()->session) {
            WC()->session->__unset('distance_fee');
        }
    }

    /**
     * Display the location data in the admin order page
     */
    public function display_custom_location_data_in_admin($order) {
        $order_id = $order->get_id();
        $options = get_option('slbr_locations_settings');
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        $currency_symbol = $currency === 'USD' ? 'pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_ : ($currency === 'EUR' ? '€' : $currency);
        
        // Pickup location
        $pickup_location = get_post_meta($order_id, '_shipping_pickup_location', true);
        echo '<p><strong>'.__('Pickup Location:', 'slbr-locations').'</strong><br>';
        
        if ($pickup_location === 'Negombo Office') {
            echo 'Sri Lanka Bike Rent Negombo Office';
        } else {
            $custom_pickup = get_post_meta($order_id, '_shipping_custom_pickup_location', true);
            echo $custom_pickup;
            
            $pickup_lat = get_post_meta($order_id, '_shipping_pickup_lat', true);
            $pickup_lng = get_post_meta($order_id, '_shipping_pickup_lng', true);
            
            if ($pickup_lat && $pickup_lng) {
                echo ' <a href="https://maps.google.com/?q='.$pickup_lat.','.$pickup_lng.'" target="_blank">'.__('View on map', 'slbr-locations').'</a>';
            }
        }
        echo '</p>';
        
        // Dropoff location
        $dropoff_location = get_post_meta($order_id, '_shipping_dropoff_location', true);
        echo '<p><strong>'.__('Drop-off Location:', 'slbr-locations').'</strong><br>';
        
        if ($dropoff_location === 'Negombo Office') {
            echo 'Sri Lanka Bike Rent Negombo Office';
        } else {
            $custom_dropoff = get_post_meta($order_id, '_shipping_custom_dropoff_location', true);
            echo $custom_dropoff;
            
            $dropoff_lat = get_post_meta($order_id, '_shipping_dropoff_lat', true);
            $dropoff_lng = get_post_meta($order_id, '_shipping_dropoff_lng', true);
            
            if ($dropoff_lat && $dropoff_lng) {
                echo ' <a href="https://maps.google.com/?q='.$dropoff_lat.','.$dropoff_lng.'" target="_blank">'.__('View on map', 'slbr-locations').'</a>';
            }
        }
        echo '</p>';
        
        // Distance information
        $pickup_distance = get_post_meta($order_id, '_shipping_pickup_distance', true);
        $dropoff_distance = get_post_meta($order_id, '_shipping_dropoff_distance', true);
        $distance_fee = get_post_meta($order_id, '_shipping_distance_fee', true);
        
        if ($pickup_distance || $dropoff_distance) {
            echo '<div class="distance-data" style="margin-top: 10px; padding: 10px; background: #f8f8f8; border-left: 3px solid #2271b1;">';
            echo '<h4 style="margin-top: 0;">Distance Information</h4>';
            
            if ($pickup_distance) {
                echo '<p><strong>Pickup Distance:</strong> ' . $pickup_distance . ' km</p>';
            }
            
            if ($dropoff_distance) {
                echo '<p><strong>Drop-off Distance:</strong> ' . $dropoff_distance . ' km</p>';
            }
            
            if ($distance_fee) {
                echo '<p><strong>Distance Fee:</strong> ' . $currency_symbol . number_format((float)$distance_fee, 2, '.', '') . ' ' . $currency . '</p>';
            }
            
            echo '</div>';
        }
    }

    /**
     * Display locations in order emails
     */
    public function add_location_details_to_emails($order, $sent_to_admin, $plain_text, $email) {
        $order_id = $order->get_id();
        $options = get_option('slbr_locations_settings');
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        $currency_symbol = $currency === 'USD' ? 'pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_ : ($currency === 'EUR' ? '€' : $currency);
        
        // Get pickup location
        $pickup_location = get_post_meta($order_id, '_shipping_pickup_location', true);
        $custom_pickup = get_post_meta($order_id, '_shipping_custom_pickup_location', true);
        
        // Get dropoff location
        $dropoff_location = get_post_meta($order_id, '_shipping_dropoff_location', true);
        $custom_dropoff = get_post_meta($order_id, '_shipping_custom_dropoff_location', true);
        
        // Get distance fee
        $distance_fee = get_post_meta($order_id, '_shipping_distance_fee', true);
        
        if ($plain_text) {
            echo "\n\n==========\n\n";
            echo "PICKUP & DROP-OFF DETAILS\n\n";
            
            echo "Pickup Location: ";
            echo ($pickup_location === 'Negombo Office') ? 
                "Sri Lanka Bike Rent Negombo Office" : esc_html($custom_pickup);
            echo "\n";
            
            echo "Drop-off Location: ";
            echo ($dropoff_location === 'Negombo Office') ? 
                "Sri Lanka Bike Rent Negombo Office" : esc_html($custom_dropoff);
            echo "\n";
            
            if (!empty($distance_fee) && $distance_fee > 0) {
                echo "Distance Fee: " . $currency_symbol . number_format((float)$distance_fee, 2, '.', '') . " " . $currency . "\n";
            }
            
            echo "\n==========\n\n";
        } else {
            ?>
            <div style="margin-bottom: 40px;">
                <h2>Pickup & Drop-off Details</h2>
                <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5; margin-bottom: 20px;">
                    <tr>
                        <th scope="row" style="text-align: left; border-bottom: 1px solid #e5e5e5; padding: 10px; font-weight: bold;">Pickup Location:</th>
                        <td style="text-align: left; border-bottom: 1px solid #e5e5e5; padding: 10px;">
                            <?php echo ($pickup_location === 'Negombo Office') ? 
                                'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_pickup); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="text-align: left; border-bottom: 1px solid #e5e5e5; padding: 10px; font-weight: bold;">Drop-off Location:</th>
                        <td style="text-align: left; border-bottom: 1px solid #e5e5e5; padding: 10px;">
                            <?php echo ($dropoff_location === 'Negombo Office') ? 
                                'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_dropoff); ?>
                        </td>
                    </tr>
                    <?php if (!empty($distance_fee) && $distance_fee > 0) : ?>
                    <tr>
                        <th scope="row" style="text-align: left; padding: 10px; font-weight: bold;">Distance Fee:</th>
                        <td style="text-align: left; padding: 10px;">
                            <?php echo $currency_symbol . number_format((float)$distance_fee, 2, '.', '') . ' ' . $currency; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php
        }
    }

    /**
     * Display pickup and dropoff information in the thank you page
     */
    public function display_locations_on_thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $options = get_option('slbr_locations_settings');
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        $currency_symbol = $currency === 'USD' ? 'pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_ : ($currency === 'EUR' ? '€' : $currency);
        
        // Get pickup location
        $pickup_location = get_post_meta($order_id, '_shipping_pickup_location', true);
        $custom_pickup = get_post_meta($order_id, '_shipping_custom_pickup_location', true);
        
        // Get dropoff location
        $dropoff_location = get_post_meta($order_id, '_shipping_dropoff_location', true);
        $custom_dropoff = get_post_meta($order_id, '_shipping_custom_dropoff_location', true);
        
        // Get distance fee
        $distance_fee = get_post_meta($order_id, '_shipping_distance_fee', true);
        
        // Display pickup and dropoff locations
        echo '<section class="woocommerce-pickup-dropoff-details">';
        echo '<h2 class="woocommerce-order-details__title">Pickup & Drop-off Details</h2>';
        
        echo '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">';
        
        // Pickup location
        echo '<tr>';
        echo '<th>Pickup Location:</th>';
        echo '<td>';
        echo ($pickup_location === 'Negombo Office') ? 
            'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_pickup);
        echo '</td>';
        echo '</tr>';
        
        // Dropoff location
        echo '<tr>';
        echo '<th>Drop-off Location:</th>';
        echo '<td>';
        echo ($dropoff_location === 'Negombo Office') ? 
            'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_dropoff);
        echo '</td>';
        echo '</tr>';
        
        // Distance fee if applicable
        if (!empty($distance_fee) && $distance_fee > 0) {
            echo '<tr>';
            echo '<th>Distance Fee:</th>';
            echo '<td>' . $currency_symbol . number_format((float)$distance_fee, 2, '.', '') . ' ' . $currency . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</section>';
    }

    /**
     * Display pickup and dropoff details in "My Account" order view
     */
    public function display_locations_in_account_orders($order) {
        $order_id = $order->get_id();
        
        $options = get_option('slbr_locations_settings');
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        $currency_symbol = $currency === 'USD' ? 'pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_ : ($currency === 'EUR' ? '€' : $currency);
        
        // Get pickup location
        $pickup_location = get_post_meta($order_id, '_shipping_pickup_location', true);
        $custom_pickup = get_post_meta($order_id, '_shipping_custom_pickup_location', true);
        
        // Get dropoff location
        $dropoff_location = get_post_meta($order_id, '_shipping_dropoff_location', true);
        $custom_dropoff = get_post_meta($order_id, '_shipping_custom_dropoff_location', true);
        
        // Get distance fee
        $distance_fee = get_post_meta($order_id, '_shipping_distance_fee', true);
        
        // Display pickup and dropoff locations
        echo '<section class="woocommerce-pickup-dropoff-details">';
        echo '<h2 class="woocommerce-order-details__title">Pickup & Drop-off Details</h2>';
        
        echo '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">';
        
        // Pickup location
        echo '<tr>';
        echo '<th>Pickup Location:</th>';
        echo '<td>';
        echo ($pickup_location === 'Negombo Office') ? 
            'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_pickup);
        echo '</td>';
        echo '</tr>';
        
        // Dropoff location
        echo '<tr>';
        echo '<th>Drop-off Location:</th>';
        echo '<td>';
        echo ($dropoff_location === 'Negombo Office') ? 
            'Sri Lanka Bike Rent Negombo Office' : esc_html($custom_dropoff);
        echo '</td>';
        echo '</tr>';
        
        // Distance fee if applicable
        if (!empty($distance_fee) && $distance_fee > 0) {
            echo '<tr>';
            echo '<th>Distance Fee:</th>';
            echo '<td>' . $currency_symbol . number_format((float)$distance_fee, 2, '.', '') . ' ' . $currency . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</section>';
    }

    /**
     * Add a message about distance fees to the top of the checkout page
     */
    public function add_distance_fee_notice() {
        $options = get_option('slbr_locations_settings');
        $rate_per_km = isset($options['rate_per_km']) ? floatval($options['rate_per_km']) : 0.35;
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        $currency_symbol = $currency === 'USD' ? 'pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_ : ($currency === 'EUR' ? '€' : $currency);
        ?>
        <div class="woocommerce-info">
            <strong>Distance Fee Information:</strong> 
            Additional fees of <?php echo $currency_symbol . number_format($rate_per_km, 2); ?> per kilometer will be applied for pickup or delivery to custom locations outside our Negombo office.
        </div>
        <?php
    }

    /**
     * Make sure the distance fee persists during page refresh and payment gateway redirects
     */
    public function add_distance_fee_to_order($order, $data) {
        // If we have a distance fee in the session, add it to the order
        if (WC()->session && $distance_fee = WC()->session->get('distance_fee')) {
            if ($distance_fee > 0) {
                $order->add_fee('Distance Fee', $distance_fee, true, '');
            }
        }
        // If it's in the POST data
        else if (isset($_POST['shipping_distance_fee']) && !empty($_POST['shipping_distance_fee'])) {
            $distance_fee = floatval($_POST['shipping_distance_fee']);
            if ($distance_fee > 0) {
                $order->add_fee('Distance Fee', $distance_fee, true, '');
            }
        }
    }

    /**
     * Preserve the distance fee throughout the checkout process 
     * This ensures it's included in the payment gateway calculations
     */
    public function ensure_distance_fee_in_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // Skip if already processing fees to avoid infinite loop
        if (did_action('woocommerce_cart_calculate_fees') > 0) {
            return;
        }
        
        // We only need to do this if we're on pages after checkout but before thank you
        if (is_checkout_pay_page() || is_wc_endpoint_url('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
            
            if ($order_id > 0) {
                $distance_fee = get_post_meta($order_id, '_shipping_distance_fee', true);
                
                if (!empty($distance_fee) && $distance_fee > 0) {
                    // Set the session variable used by our fee calculation function
                    if (WC()->session) {
                        WC()->session->set('distance_fee', $distance_fee);
                    }
                }
            }
        }
    }

    /**
     * Store distance fee in session when payment begins
     */
    public function store_distance_fee_before_payment($order) {
        if (!$order) return;
        
        $distance_fee = get_post_meta($order->get_id(), '_shipping_distance_fee', true);
        
        if (!empty($distance_fee) && $distance_fee > 0 && WC()->session) {
            WC()->session->set('distance_fee', $distance_fee);
        }
    }

    /**
     * Ensure distance fee is included in payment gateways
     */
    public function add_distance_fee_to_order_totals($total_rows, $order, $tax_display) {
        // Check if there's a distance fee
        $distance_fee = $order->get_meta('_shipping_distance_fee');
        
        if (!empty($distance_fee) && $distance_fee > 0) {
            // If the fee wasn't already added as a line item, we need to show it
            $fee_found = false;
            
            // Check if the fee is already included in the order items
            foreach ($order->get_fees() as $fee) {
                if ($fee->get_name() === 'Distance Fee') {
                    $fee_found = true;
                    break;
                }
            }
            
            // If not found, manually insert it into the totals display
            if (!$fee_found) {
                $new_rows = [];
                
                foreach ($total_rows as $key => $row) {
                    $new_rows[$key] = $row;
                    
                    // Add distance fee before the total
                    if ($key === 'order_total') {
                        $new_rows['distance_fee'] = [
                            'label' => __('Distance Fee:', 'slbr-locations'),
                            'value' => wc_price($distance_fee, ['currency' => $order->get_currency()])
                        ];
                    }
                }
                
                return $new_rows;
            }
        }
        
        return $total_rows;
    }
    
    /**
     * Add settings page to the admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Bike Rental Locations Settings', 'slbr-locations'),
            __('Bike Rental Locations', 'slbr-locations'),
            'manage_options',
            'slbr-locations',
            array($this, 'display_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('slbr_locations_settings_group', 'slbr_locations_settings');
        
        add_settings_section(
            'slbr_locations_main_section',
            __('General Settings', 'slbr-locations'),
            array($this, 'settings_section_callback'),
            'slbr-locations'
        );
        
        add_settings_field(
            'google_maps_api_key',
            __('Google Maps API Key', 'slbr-locations'),
            array($this, 'google_maps_api_key_callback'),
            'slbr-locations',
            'slbr_locations_main_section'
        );
        
        add_settings_field(
            'rate_per_km',
            __('Rate per Kilometer', 'slbr-locations'),
            array($this, 'rate_per_km_callback'),
            'slbr-locations',
            'slbr_locations_main_section'
        );
        
        add_settings_field(
            'currency',
            __('Currency', 'slbr-locations'),
            array($this, 'currency_callback'),
            'slbr-locations',
            'slbr_locations_main_section'
        );
        
        add_settings_field(
            'office_coordinates',
            __('Office Coordinates', 'slbr-locations'),
            array($this, 'office_coordinates_callback'),
            'slbr-locations',
            'slbr_locations_main_section'
        );
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . __('Configure settings for Bike Rental Locations plugin.', 'slbr-locations') . '</p>';
    }
    
    /**
     * Google Maps API Key field callback
     */
    public function google_maps_api_key_callback() {
        $options = get_option('slbr_locations_settings');
        $api_key = isset($options['google_maps_api_key']) ? esc_attr($options['google_maps_api_key']) : '';
        ?>
        <input type="text" name="slbr_locations_settings[google_maps_api_key]" value="<?php echo $api_key; ?>" class="regular-text">
        <p class="description"><?php _e('Enter your Google Maps API Key. Required for maps and distance calculation.', 'slbr-locations'); ?></p>
        <?php
    }
    
    /**
     * Rate per kilometer field callback
     */
    public function rate_per_km_callback() {
        $options = get_option('slbr_locations_settings');
        $rate = isset($options['rate_per_km']) ? floatval($options['rate_per_km']) : 0.35;
        ?>
        <input type="number" step="0.01" min="0" name="slbr_locations_settings[rate_per_km]" value="<?php echo $rate; ?>" class="small-text">
        <p class="description"><?php _e('Enter the rate per kilometer for distance fees.', 'slbr-locations'); ?></p>
        <?php
    }
    
    /**
     * Currency field callback
     */
    public function currency_callback() {
        $options = get_option('slbr_locations_settings');
        $currency = isset($options['currency']) ? esc_attr($options['currency']) : 'USD';
        ?>
        <select name="slbr_locations_settings[currency]">
            <option value="USD" <?php selected($currency, 'USD'); ?>>USD ($)</option>
            <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR (€)</option>
            <option value="GBP" <?php selected($currency, 'GBP'); ?>>GBP (£)</option>
            <option value="LKR" <?php selected($currency, 'LKR'); ?>>LKR (Rs)</option>
        </select>
        <p class="description"><?php _e('Select the currency to use for distance fees.', 'slbr-locations'); ?></p>
        <?php
    }
    
    /**
     * Office coordinates field callback
     */
    public function office_coordinates_callback() {
        $options = get_option('slbr_locations_settings');
        $lat = isset($options['office_lat']) ? floatval($options['office_lat']) : 7.2095;
        $lng = isset($options['office_lng']) ? floatval($options['office_lng']) : 79.8384;
        ?>
        <div style="margin-bottom: 10px;">
            <label style="display: inline-block; width: 100px;"><?php _e('Latitude:', 'slbr-locations'); ?></label>
            <input type="text" name="slbr_locations_settings[office_lat]" value="<?php echo $lat; ?>" class="regular-text">
        </div>
        <div>
            <label style="display: inline-block; width: 100px;"><?php _e('Longitude:', 'slbr-locations'); ?></label>
            <input type="text" name="slbr_locations_settings[office_lng]" value="<?php echo $lng; ?>" class="regular-text">
        </div>
        <p class="description"><?php _e('Enter the coordinates of your office location. Default is set to Negombo, Sri Lanka.', 'slbr-locations'); ?></p>
        <?php
    }
    
    /**
     * Display settings page
     */
    public function display_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Bike Rental Locations Settings', 'slbr-locations'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('slbr_locations_settings_group');
                do_settings_sections('slbr-locations');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Initialize the plugin
$slbr_locations = new SLBR_Locations();
pickup_distance'])) {
            update_post_meta($order_id, '_shipping_pickup_distance', sanitize_text_field($_POST['shipping_pickup_distance']));
        }
        
        if (!empty($_POST['shipping_dropoff_distance'])) {
            update_post_meta($order_id, '_shipping_dropoff_distance', sanitize_text_field($_POST['shipping_dropoff_distance']));
        }
        
        if (!empty($_POST['shipping_distance_fee'])) {
            update_post_meta($order_id, '_shipping_distance_fee', sanitize_text_field($_POST['shipping_distance_fee']));
        } else if (WC()->session && WC()->session->get('distance_fee')) {
            // If not in POST data, but available in session, use that
            update_post_meta($order_id, '_shipping_distance_fee', WC()->session->get('distance_fee'));
        }
        
        // Save coordinates
        if (!empty($_POST['shipping_pickup_lat']) && !empty($_POST['shipping_pickup_lng'])) {
            update_post_meta($order_id, '_shipping_pickup_lat', sanitize_text_field($_POST['shipping_pickup_lat']));
            update_post_meta($order_id, '_shipping_pickup_lng', sanitize_text_field($_POST['shipping_pickup_lng']));
        }
        
        if (!empty($_POST['shipping_