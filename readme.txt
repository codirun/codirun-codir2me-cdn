=== Codirun R2 Media & Static CDN ===
Contributors: brunoeduardo
Tags: cloudflare, r2, cdn, offload, image optimization
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.4
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload JS, CSS, SVG, fonts and images to Cloudflare R2 and serve them via Cloudflare CDN to speed up your WordPress site and reduce server load.

== Description ==

The Codirun R2 Media & Static CDN plugin allows you to upload static files (JS, CSS, SVG, fonts) and images to Cloudflare R2 and change the URLs to point to the CDN, resulting in a faster site and greater bandwidth savings.

== Key Features ==

* Upload JS, CSS, SVG, fonts and images to Cloudflare R2
* Replace local URLs with CDN URLs
* Compress and optimize images without quality loss
* Convert images to WebP and AVIF
* Batch upload and image reprocessing
* Intuitive WordPress admin interface

== Premium Features ==

* Advanced image optimization and reprocessing
* R2 bucket sync
* File deletion in R2 directly from WordPress

== External Services ==

This plugin connects to and relies on several external services to provide its functionality. Below is detailed information about each service:

= Cloudflare R2 Storage =
**What it is:** Cloud storage service provided by Cloudflare for storing your website's static files and images.
**What data is sent:** Your website's static files (JavaScript, CSS, SVG, fonts) and images are uploaded to your Cloudflare R2 bucket.
**When data is sent:** When you manually upload files through the plugin interface or when automatic upload is enabled.
**Purpose:** To serve your files via Cloudflare's global CDN network for improved performance and reduced server load.
**User control:** You provide your own R2 credentials and can disable the service at any time.
**Privacy policy:** https://www.cloudflare.com/privacypolicy/
**Terms of service:** https://www.cloudflare.com/terms/

= License Validation Service =
**What it is:** API service (r2cdn.codirun.com) used to validate premium licenses.
**What data is sent:** License key, website domain, and basic WordPress installation information.
**When data is sent:** When activating/deactivating premium features or during periodic license validation checks.
**Purpose:** To verify if your license is valid and grant access to premium features.
**User control:** Only premium users need to provide license keys. Free features work without any license validation.
**Privacy policy:** This service is operated by the plugin author and does not store personal user data beyond the license validation requirements.

= Stripe Payment Processing =
**What it is:** Third-party payment processor for purchasing premium licenses.
**What data is sent:** Payment information (credit card details, billing address) is sent directly to Stripe when purchasing a license.
**When data is sent:** Only when you choose to purchase a premium license through the provided Stripe checkout links.
**Purpose:** To process license purchases securely.
**User control:** Payment is entirely optional and only required for premium features.
**Privacy policy:** https://stripe.com/privacy
**Terms of service:** https://stripe.com/terms

**Important:** All connections to external services are made only when explicitly configured by the site administrator or when purchasing premium features. The plugin does not collect or transmit any visitor data or personal information without explicit user action.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/codirun-codir2me-cdn` directory, or install it via the WordPress plugin panel.
2. Activate it through the 'Plugins' menu in WordPress.
3. Access the 'Codirun R2 Media & Static CDN' menu to set your credentials.
4. Configure your R2 bucket and custom CDN domain.
5. Start uploading and optimizing.

== Frequently Asked Questions ==

= What Cloudflare R2 credentials do I need? =

You need:
- R2 Access Key
- R2 Secret Key
- R2 Bucket Name
- R2 Endpoint URL
- Optional: Custom CDN Domain

= Does the plugin support thumbnails? =

Yes, you can choose which thumbnail sizes to upload to R2.

= Can I use this without a custom domain? =

Yes, using the R2 endpoint works, but a custom domain improves performance.

= Are there file size limits? =

Only those imposed by your PHP/server settings. Recommended: keep files under 10MB.

= Which image formats are supported? =

JPEG, PNG, GIF, and WebP — all can be converted to WebP and AVIF.

= Is my data safe with external services? =

Yes. Cloudflare R2 is a secure cloud storage service with enterprise-grade security. License validation only sends necessary verification data. Payment processing through Stripe uses industry-standard security measures.

= Can I use the plugin without external services? =

The core functionality requires Cloudflare R2 for file storage. Premium features require license validation. All services are clearly documented and under your control.

== Screenshots ==

1. Plugin settings panel
2. Static file uploader
3. Media library integration
4. Optimization tools
5. Reprocessing interface
6. License management

== Changelog ==

= 1.0.4 =
* Fixed minor bugs

= 1.0.3 =
* Fixed minor bugs

= 1.0.2 =
* Fixed minor bugs
* Updated readme with external services documentation

= 1.0.1 =
* Fixed minor bugs
* Improved performance and compatibility
* Updated readme with external services documentation

= 1.0.0 =
* Initial release

== Requirements ==

* WordPress 6.0+
* PHP 8.2+
* Cloudflare account with R2 enabled
* AWS SDK for PHP (plugin provides setup guide)

== Privacy Policy ==

This plugin interacts with external services as documented in the "External Services" section above.

The plugin itself does not collect any personal user or visitor data beyond what is necessary for its core functionality (file upload and CDN integration).

For detailed privacy information about external services:
- Cloudflare: https://www.cloudflare.com/privacypolicy/
- Stripe: https://stripe.com/privacy

== 🇧🇷 Versão em Português (resumo) ==

**Codirun R2 Media & Static CDN** permite enviar JS, CSS, fontes, SVGs e imagens para o Cloudflare R2, convertendo e otimizando para formatos modernos como WebP e AVIF.

**Principais recursos:**
- Upload automático de arquivos estáticos
- Otimização e compressão de imagens
- Conversão para WebP e AVIF
- Sincronização com bucket R2
- Gerenciamento direto via painel do WordPress

**Recursos Premium:**
- Otimização avançada
- Reprocessamento em massa
- Exclusão de arquivos direto no bucket

**Serviços Externos:**
O plugin utiliza o Cloudflare R2 para armazenamento, validação de licenças para recursos premium, e Stripe para processamento de pagamentos. Todos os serviços são opcionais ou controláveis pelo usuário.

Requer: WordPress 6.0+, PHP 8.2+ e conta Cloudflare com R2 ativado.