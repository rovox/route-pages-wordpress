# Trufi Route Pages

![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)

Trufi Route Pages is a Wordpress plugin developed by [The Trufi Association](https://trufi.app/) to complement the Trufi mobile application. It allows Wordpress to receive a URL request containing a route ID and name and display it as a post. The plugin also generates a dynamic sitemap.xml based on route information from the openGraphql API.

## About The Trufi Association

We are a non-profit NGO founded by a group of German and Bolivian volunteers in 2019. Our mission is to transform mobility in cities with open source apps and open data solutions that enable innovation. We help cities realize a public transport app, even in places where transport is unregulated.

## Plugin Information

* **Plugin Name**: Trufi Route Pages
* **Plugin URI**: [https://trufi.app/](https://trufi.app/)
* **Description**: A plugin for displaying Trufi Maps.
* **Version**: 1.0
* **Author**: Trufi Association
* **Author URI**: [https://trufi.app/](https://trufi.app/)
* **License**: GPL2
* **License URI**: [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
* **Text Domain**: TrufiApi-maps

## Installation

1. Compress the plugin files in a .zip file named `TrufiApi-maps`.
2. Use the regular Wordpress plugin installation process to install the .zip file.
3. Navigate to Wordpress administration and then configuration.
4. Set the required values for this plugin, such as the Graphql server URL.
   1. If your are using OpenTripPlanner v1, the Graphql URL might be something like `https://yourotp.example.com/otp/routers/default/index/graphql`
   2. You need to create a new empty base page for the routes

## Testing

You can test if the plugin works by accessing the URL path `/wp-sitemap-routes-1.xml` of your page.

## Contributing

We appreciate all contributions! If you're interested in contributing, please see our [contributing guidelines](CONTRIBUTING.md).

## License

This project is licensed under the [GPL v2 License](https://www.gnu.org/licenses/gpl-2.0.html) - see the [LICENSE](LICENSE) file for details.

## Contact

For more information, you can reach us through our [website](https://trufi.app/).
