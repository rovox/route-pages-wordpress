<?php global $plugin_url; ?>
<div class="wrap">
    <h1><img src="<?php echo $plugin_url . '/assets/img/trufi-logo.svg' ?>" alt="Trufi Logo"/>&nbsp;<?php echo esc_html(get_admin_page_title()); ?></h1>
    <form method="post" action="">
        <?php wp_nonce_field('trufi_api_options_nonce', 'trufi_api_options_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="trufi_api_url"><?php _e('API url', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_api_url" id="trufi_api_url" value="<?php echo esc_attr($api_url); ?>" class="large-text">
                    <p class="description"><?php _e('Enter the URL of the Trufi Graphql API.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_site_description"><?php _e('Marketing Call to Action', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_site_description" id="trufi_site_description" value="<?php echo esc_attr($site_description); ?>"
                           maxlength="200" class="large-text">
                    <p class="description"><span id="chars_left">200</span> <?php _e('characters remaining', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_line_color"><?php _e('Line color', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_line_color" id="trufi_line_color" value="<?php echo esc_attr($line_color); ?>" class="line-color-field">
                    <p class="description"><?php _e('Choose a color for the track line.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_line_weight"><?php _e('Line Weight', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="number" name="trufi_line_weight" id="trufi_line_weight" value="<?php echo esc_attr($line_weight); ?>" class="small-text"
                           min="1" max="10">
                    <p class="description"><?php _e('Enter the line weight (1-10).', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_google_play_url"><?php _e('Google Play Store URL', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_google_play_url" id="trufi_google_play_url" value="<?php echo esc_attr($google_play_url); ?>"
                           class="large-text">
                    <p class="description"><?php _e('Enter the URL of your app in the Google Play Store.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_apple_store_url"><?php _e('Apple Store URL', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_apple_store_url" id="trufi_apple_store_url" value="<?php echo esc_attr($apple_store_url); ?>"
                           class="large-text">
                    <p class="description"><?php _e('Enter the URL of your app in the Apple App Store.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_google_play_image"><?php _e('Google Play Store Image', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="hidden" name="trufi_google_play_image" id="trufi_google_play_image" value="<?php echo esc_attr($google_play_image); ?>">
                    <button type="button" class="upload_image_button button"><?php _e('Upload Image', 'TrufiApi-maps'); ?></button>
                    <p class="description"><?php _e('Upload an image for the Google Play Store button.', 'TrufiApi-maps'); ?></p>
                    <img id="trufi_google_play_image_preview" src="<?php echo esc_attr($google_play_image); ?>"
                         alt="Google Play Store Image"
                         style="<?php echo(empty($google_play_image) ? 'display: none;' : ''); ?> max-width: 200px; max-height: 200px;">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_apple_store_image"><?php _e('Apple Store Image', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="hidden" name="trufi_apple_store_image" id="trufi_apple_store_image" value="<?php echo esc_attr($apple_store_image); ?>">
                    <button type="button" class="upload_image_button button"><?php _e('Upload Image', 'TrufiApi-maps'); ?></button>
                    <p class="description"><?php _e('Upload an image for the Apple Store button.', 'TrufiApi-maps'); ?></p>
                    <img id="trufi_apple_store_image_preview" src="<?php echo esc_attr($apple_store_image); ?>"
                         alt="Apple Store Image"
                         style="<?php echo(empty($apple_store_image) ? 'display: none;' : ''); ?> max-width: 200px; max-height: 200px;">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_map_page_id"><?php _e('Base Page for Maps', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <?php wp_dropdown_pages(['name'              => 'trufi_map_page_id',
                                             'id'                => 'trufi_map_page_id',
                                             'show_option_none'  => 'Select Page',
                                             'option_none_value' => 0,
                                             'selected'          => $map_page_id,
                    ]) ?>
                    <p class="description"><?php _e('Choose the base page to use for the map pages.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_cache_ttl"><?php _e('Cache TTL (Hours)', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="number" name="trufi_cache_ttl" id="trufi_cache_ttl" value="<?php echo esc_attr($cache_ttl); ?>" min="0" step="1" class="small-text">
                    <p class="description"><?php _e('Enter the time to live (in hours) for the sitemap cache. Set to 0 to disable.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_map_center_lat"><?php _e('Default Map Center', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <input type="text" name="trufi_map_center_lat" id="trufi_map_center_lat" value="<?php echo esc_attr($map_center_lat); ?>" class="small-text" placeholder="Latitude">
                    <input type="text" name="trufi_map_center_lng" id="trufi_map_center_lng" value="<?php echo esc_attr($map_center_lng); ?>" class="small-text" placeholder="Longitude">
                    <input type="number" name="trufi_map_zoom" id="trufi_map_zoom" value="<?php echo esc_attr($map_zoom); ?>" min="1" max="20" class="small-text" placeholder="Zoom">
                    <p class="description"><?php _e('Latitude, longitude and zoom level used to center the routes index map before a route is selected.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="trufi_show_location"><?php _e('Location button', 'TrufiApi-maps'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" name="trufi_show_location" id="trufi_show_location" value="1" <?php checked($show_location, '1'); ?>>
                        <?php _e('Show the "Mi ubicación" button on the maps', 'TrufiApi-maps'); ?>
                    </label>
                    <p class="description"><?php _e('Uses the browser GPS, falling back to an approximate IP-based location if GPS is unavailable.', 'TrufiApi-maps'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save Changes', 'TrufiApi-maps')); ?>
    </form>
</div>
