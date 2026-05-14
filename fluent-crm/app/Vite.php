<?php

namespace FluentCrm\App;

use Exception;
use FluentCrm\Framework\Support\Arr;

class Vite
{
    private array $moduleScripts = [];
    private bool $isScriptFilterAdded = false;
    private string $viteHostProtocol = 'http://';
    private string $viteHost = 'localhost';
    private string $vitePort = '5174';
    private string $resourceDirectory = 'resources/';

    protected static ?Vite $instance = null;
    public ?string $lastJsHandle = null;
    private ?array $manifestData = null;
    private array $enqueuedChunkCss = [];

    public function __construct()
    {
        $serverConfigPath = FLUENTCRM_PLUGIN_PATH . 'config' . DIRECTORY_SEPARATOR . 'vite.json';
        if (file_exists($serverConfigPath)) {
            $serverConfig = json_decode(file_get_contents($serverConfigPath));
            $this->viteHost = $serverConfig->host ?: $this->viteHost;
            $this->viteHostProtocol = $serverConfig->protocol ?: $this->viteHostProtocol;
            $this->vitePort = $serverConfig->port ?: $this->vitePort;
        }
        
        // Add global filter to convert Vite scripts to modules
        add_filter('script_loader_tag', [$this, 'maybeConvertToModule'], 999, 3);
    }
    
    /**
     * Convert scripts from Vite dev server or built assets to ES modules
     */
    public function maybeConvertToModule($tag, $handle, $src): string
    {
        // Check if this script is from Vite dev server, Vite built assets, or is a module script
        $isViteScript = false;
        $fluentCrmAssetBase = FLUENTCRM_PLUGIN_URL . 'assets/';
        
        // Check if from dev server
        if (strpos($src, 'localhost:' . $this->vitePort) !== false || strpos($src, '@vite/client') !== false) {
            $isViteScript = true;
        }
        
        // Check if explicitly marked as module script
        if (in_array($handle, $this->moduleScripts)) {
            $isViteScript = true;
        }
        
        // Check if from FluentCRM Vite built assets in production (or dev mode without server).
        // Only rewrite this plugin's own built files, never third-party plugin assets.
        if (!$this->shouldServeViaDevServer()) {
            $assetPatterns = [
                $fluentCrmAssetBase . 'admin/',
                $fluentCrmAssetBase . 'public/',
            ];
            foreach ($assetPatterns as $pattern) {
                if (strpos($src, $pattern) !== false && strpos($src, '.js') !== false) {
                    // Exclude third-party libs that are not ES modules
                    $excludePatterns = [
                        '/libs/',
                        '/vendor/',
                        'purify.min.js',
                    ];
                    $isExcluded = false;
                    foreach ($excludePatterns as $exclude) {
                        if (strpos($src, $exclude) !== false) {
                            $isExcluded = true;
                            break;
                        }
                    }
                    if (!$isExcluded) {
                        $isViteScript = true;
                        break;
                    }
                }
            }
        }
        
        if ($isViteScript) {
            // Already has type="module"
            if (strpos($tag, 'type="module"') !== false || strpos($tag, "type='module'") !== false) {
                return $tag;
            }
            
            // Convert to module
            $tag = preg_replace('/<script\s+/', '<script type="module" crossorigin ', $tag);
        }
        
        return $tag;
    }

    private static function getInstance(): Vite
    {
        if (static::$instance === null) {
            static::$instance = new static();
            // Load manifest whenever Vite dev server is NOT running.
            // This covers both production (env != dev) AND dev installs where
            // the Vite server isn't active, so built assets are served instead.
            if (!static::$instance->usingDevMode() || !static::$instance->isViteServerRunning()) {
                (static::$instance)->loadViteManifest();
            }
        }

        return static::$instance;
    }

    /**
     * @throws Exception
     */
    private function loadViteManifest()
    {
        if (!empty($this->manifestData)) {
            return;
        }

        $manifestPath = FLUENTCRM_PLUGIN_PATH . 'config' . DIRECTORY_SEPARATOR . 'vite_config.php';
        
        if (file_exists($manifestPath)) {
            $this->manifestData = require $manifestPath;
        }

        if (empty($this->manifestData)) {
            $this->manifestData = [];
            // In production, you might want to uncomment this to enforce manifest requirement
            // throw new Exception('Vite Manifest Not Found. Run: npm run dev or npm run build');
        }
    }

    public static function enqueueScript($handle, $src, $dependency = [], $version = null, $inFooter = false): Vite
    {
        return static::getInstance()->enqueue_script(
            $handle,
            $src,
            $dependency,
            $version,
            $inFooter
        );
    }

    private function enqueue_script($handle, $src, $dependency = [], $version = null, $inFooter = false): Vite
    {
        if (in_array($handle, $this->moduleScripts)) {
            if ($this->usingDevMode()) {
                $callerReference = (debug_backtrace(2)[1]);
                $fileName = explode('plugins', $callerReference['file']);
                $line = $callerReference['line'];
                // Uncomment to debug duplicate handles
                // throw new \Exception("Handle already used: $handle at File: {$fileName[1]} Line: $line");
            }
        }

        $this->moduleScripts[] = $handle;
        $this->lastJsHandle = $handle;

        if (!$this->isScriptFilterAdded) {
            add_filter('script_loader_tag', function ($tag, $handle, $src) {
                return $this->addModuleToScript($tag, $handle, $src);
            }, 10, 3);
            $this->isScriptFilterAdded = true;
        }

        if ($this->shouldServeViaDevServer()) {
            $srcPath = $this->getVitePath() . $src;
        } else {
            $assetFile = $this->getFileFromManifest($src);
            $srcPath = $this->getProductionFilePath($assetFile);
        }

        if (empty($srcPath)) {
            return $this;
        }

        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;

        wp_enqueue_script(
            $handle,
            $srcPath,
            $dependency,
            $version,
            $inFooter
        );
        
        return $this;
    }

    private function addModuleToScript($tag, $handle, $src): string
    {
        if (in_array($handle, $this->moduleScripts)) {
            // For Vue 3 and ES6 modules, we need type="module"
            // Check if this is from Vite dev server or production build
            if ($this->usingDevMode() || strpos($src, 'assets/') !== false) {
                $tag = '<script type="module" crossorigin src="' . esc_url($src) . '" id="' . esc_attr($handle) . '-js"></script>' . "\n";
            } else {
                $tag = '<script type="module" src="' . esc_url($src) . '" id="' . esc_attr($handle) . '-js"></script>' . "\n";
            }
        }
        return $tag;
    }

    private function getFileFromManifest($src)
    {
        if (isset($this->manifestData[$this->resourceDirectory . $src])) {
            return $this->manifestData[$this->resourceDirectory . $src];
        }

        return '';
    }

    private function getProductionFilePath($file): string
    {
        if (!isset($file['file'])) {
            return '';
        }
        
        $assetPath = static::getAssetPath();
        $this->ensureChunkCssIsLoaded($file);

        return ($assetPath . $file['file']);
    }

    // CSS files already merged into style.css — skip individual enqueue
    private array $mergedIntoStyleCss = [
        'admin/css/vendor.css',
        'admin/css/vendor-element-plus.css',
        'admin/visual-editor/visual-editor.css',
    ];

    private function ensureChunkCssIsLoaded($file)
    {
        $assetPath = static::getAssetPath();
        $cssFiles = $this->collectChunkCssFiles($file);

        foreach ($cssFiles as $cssPath) {
            if (isset($this->enqueuedChunkCss[$cssPath])) {
                continue;
            }

            if (in_array($cssPath, $this->mergedIntoStyleCss)) {
                $this->enqueuedChunkCss[$cssPath] = true;
                continue;
            }

            wp_enqueue_style(
                'fluentcrm_vite_css_' . md5($cssPath),
                $assetPath . $cssPath,
                [],
                FLUENTCRM_PLUGIN_VERSION
            );

            $this->enqueuedChunkCss[$cssPath] = true;
        }
    }

    private function collectChunkCssFiles($file, &$visited = []): array
    {
        $cssFiles = [];

        if (!is_array($file)) {
            return $cssFiles;
        }

        $fileId = isset($file['file']) ? $file['file'] : md5(wp_json_encode($file));
        if (isset($visited[$fileId])) {
            return $cssFiles;
        }
        $visited[$fileId] = true;

        if (isset($file['css']) && is_array($file['css'])) {
            foreach ($file['css'] as $path) {
                if (is_string($path) && $path !== '') {
                    $cssFiles[] = $path;
                }
            }
        }

        if (isset($file['imports']) && is_array($file['imports'])) {
            foreach ($file['imports'] as $importKey) {
                if (!isset($this->manifestData[$importKey]) || !is_array($this->manifestData[$importKey])) {
                    continue;
                }

                $cssFiles = array_merge($cssFiles, $this->collectChunkCssFiles($this->manifestData[$importKey], $visited));
            }
        }

        return array_values(array_unique($cssFiles));
    }

    public function with($params)
    {
        if (!is_array($params) || !Arr::isAssoc($params) || empty($this->lastJsHandle)) {
            $this->lastJsHandle = null;
            return;
        }

        foreach ($params as $key => $val) {
            wp_localize_script($this->lastJsHandle, $key, $val);
        }
        $this->lastJsHandle = null;
    }

    public static function enqueueStyle($handle, $src, $dependency = [], $version = null, $media = 'all')
    {
        static::getInstance()->enqueue_style(
            $handle,
            $src,
            $dependency,
            $version,
            $media
        );
    }

    private function enqueue_style($handle, $src, $dependency = [], $version = null, $media = 'all')
    {
        if ($this->shouldServeViaDevServer()) {
            $srcPath = $this->getVitePath() . $src;
        } else {
            $assetFile = $this->getFileFromManifest($src);
            $srcPath = $this->getProductionFilePath($assetFile);
        }

        if (empty($srcPath)) {
            return;
        }

        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;

        wp_enqueue_style(
            $handle,
            $srcPath,
            $dependency,
            $version,
            $media
        );
    }

    public static function enqueueStaticScript($handle, $src, $dependency = [], $version = null, $inFooter = false): Vite
    {
        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;

        return static::getInstance()->enqueue_static_script(
            $handle,
            $src,
            $dependency,
            $version,
            $inFooter
        );
    }

    private function enqueue_static_script($handle, $src, $dependency = [], $version = null, $inFooter = false): Vite
    {
        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;
        
        wp_enqueue_script(
            $handle,
            $this->getStaticEnqueuePath($src),
            $dependency,
            $version,
            $inFooter
        );

        return $this;
    }

    private function getStaticEnqueuePath($path): string
    {
        if ($this->shouldServeViaDevServer()) {
            return $this->getVitePath() . $path;
        }

        return $this->get_asset_url($path);
    }

    public static function enqueueStaticStyle($handle, $src, $dependency = [], $version = null, $media = 'all')
    {
        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;

        static::getInstance()->enqueue_static_style(
            $handle, $src, $dependency, $version, $media
        );
    }

    private function enqueue_static_style($handle, $src, $dependency = [], $version = null, $media = 'all')
    {
        $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;

        wp_enqueue_style(
            $handle,
            $this->getStaticEnqueuePath($src),
            $dependency,
            $version,
            $media
        );
    }

    public static function underDevelopment(): bool
    {
        return static::getInstance()->usingDevMode();
    }

    public function usingDevMode(): bool
    {
        $app = FluentCrm();
        return $app['config']->get('app.env') === 'dev';
    }

    /**
     * True only when env=dev AND the Vite dev server is reachable.
     * Use this — not usingDevMode() — to decide whether to proxy URLs to
     * the Vite server. When the server is down we fall back to built assets.
     */
    private function shouldServeViaDevServer(): bool
    {
        return $this->usingDevMode() && $this->isViteServerRunning();
    }

    /**
     * Check if Vite dev server is actually running
     */
    private function isViteServerRunning(): bool
    {
        static $isRunning = null;
        
        if ($isRunning !== null) {
            return $isRunning;
        }
        
        // Check if Vite client endpoint is accessible
        $viteUrl = $this->viteHostProtocol . $this->viteHost . ':' . $this->vitePort . '/@vite/client';
        
        $response = wp_remote_get($viteUrl, [
            'timeout' => 1,
            'sslverify' => false
        ]);
        
        $isRunning = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        return $isRunning;
    }

    public function getVitePath(): string
    {
        $protocol = rtrim($this->viteHostProtocol, ':/');
        $host = rtrim($this->viteHost, '/');
        $port = $this->vitePort;
        $resource = ltrim($this->resourceDirectory, '/');

        return sprintf('%s://%s:%s/%s', $protocol, $host, $port, $resource);
    }

    public static function getEnqueuePath($path = ''): string
    {
        $vite = static::getInstance();
        
        // Normalize the path - remove leading slash
        $path = ltrim($path, '/');

        if (!$vite->usingDevMode()) {
            // In production, map the path to source path first for manifest lookup
            $sourcePath = $vite->getSourcePathForManifest($path);
            $assetFile = $vite->getFileFromManifest($sourcePath);
            if ($assetFile) {
                $srcPath = $vite->getProductionFilePath($assetFile);
            } else {
                // Fallback to direct asset path
                $srcPath = static::getAssetPath() . $path;
            }
        } else {
            // Check if Vite dev server is actually running
            if ($vite->isViteServerRunning()) {
                // Use Vite dev server URL (source path, served via HMR)
                $srcPath = $vite->mapToSourcePath($path);
            } else {
                // Vite server not running — resolve via manifest just like production.
                // The plugin ships with env=dev, so without this the fallback would use
                // the old Mix path pattern (assets/admin/js/app.js) which doesn't
                // exist in the Vite output (assets/admin/app.js). 404 = blank app.
                $sourcePath = $vite->getSourcePathForManifest($path);
                $assetFile = $vite->getFileFromManifest($sourcePath);
                if ($assetFile) {
                    $srcPath = $vite->getProductionFilePath($assetFile);
                } else {
                    // Last resort: direct path (for assets not in manifest)
                    $srcPath = static::getAssetPath() . $path;
                }
            }
        }

        return $srcPath;
    }
    
    /**
     * Map built asset paths to source paths for dev mode
     */
    private function mapToSourcePath($path): string
    {
        // CSS mappings (built path -> source path, WITHOUT resources/ prefix)
        $cssMap = [

            'admin/css/admin_rtl.css' => 'scss/admin_rtl.scss',
            'admin/css/app_global.css' => 'scss/app_global.scss',
            'admin/css/setup-wizard.css' => 'scss/setup-wizard.scss',
            'admin/css/app3.css' => 'styles/app3.scss',
            'public/public_pref.css' => 'scss/public_pref.scss',
        ];
        
        // JS mappings (built path -> source path, WITHOUT resources/ prefix)
        $jsMap = [
            'admin/js/boot.js' => 'admin/boot.js',
            'admin/js/app.js' => 'admin/app.js',
            'admin/js/adminbar-search.js' => 'admin/adminbar-search.js',
            'admin/js/global_admin.js' => 'admin/global_admin.js',
            'admin/js/setup-wizard.js' => 'admin/setup-wizard.js',
            'admin/js/contact-navigations.js' => 'admin/experiments/contact-navigations.js',
            'admin/js/visual-editor.js' => 'admin/visual-editor/visual-editor.js',
            'public/public_pref.js' => 'public/public_pref.js',
        ];
        
        // Combine all mappings
        $pathMap = array_merge($cssMap, $jsMap);
        
        // Check if we have a direct mapping
        if (isset($pathMap[$path])) {
            // Return Vite path with resources/ prefix added once
            return $this->getVitePath() . $pathMap[$path];
        }
        
        // If path already starts with resources/, use it as-is
        if (strpos($path, 'resources/') === 0) {
            return $this->getVitePath() . substr($path, 10); // Remove 'resources/' prefix
        }
        
        // Fallback: use the path as-is (it will be added to getVitePath which includes resources/)
        return $this->getVitePath() . $path;
    }
    
    /**
     * Map Mix-style paths to Vite source paths for manifest lookup
     * This is the reverse of mapToSourcePath - used in production
     */
    private function getSourcePathForManifest($path): string
    {
        // Map Mix paths (what code calls) to Vite source paths (what's in manifest)
        $pathMap = [
            // JS files (Mix uses admin/js/*, Vite uses admin/*)
            'admin/js/boot.js' => 'admin/boot.js',
            'admin/js/app.js' => 'admin/app.js',
            'admin/js/adminbar-search.js' => 'admin/adminbar-search.js',
            'admin/js/global_admin.js' => 'admin/global_admin.js',
            'admin/js/setup-wizard.js' => 'admin/setup-wizard.js',
            'admin/js/contact-navigations.js' => 'admin/experiments/contact-navigations.js',
            'admin/js/visual-editor.js' => 'admin/visual-editor/visual-editor.js',
            'public/public_pref.js' => 'public/public_pref.js',
            
            // CSS files (Mix uses admin/css/*, Vite uses scss/* or styles/*)

            'admin/css/admin_rtl.css' => 'scss/admin_rtl.scss',
            'admin/css/app_global.css' => 'scss/app_global.scss',
            'admin/css/setup-wizard.css' => 'scss/setup-wizard.scss',
            'admin/css/app3.css' => 'styles/app3.scss',
            'public/public_pref.css' => 'scss/public_pref.scss',
        ];
        
        // If we have a direct mapping, return it
        if (isset($pathMap[$path])) {
            return $pathMap[$path];
        }
        
        // Otherwise return the original path (for static assets, etc.)
        return $path;
    }

    public static function getAssetUrl($path = ''): string
    {
        return esc_url(static::getInstance()->get_asset_url($path) ?? '');
    }

    private function get_asset_url($path = ''): string
    {
        if ($this->shouldServeViaDevServer()) {
            return $this->getVitePath() . $path;
        }

        return FLUENTCRM_PLUGIN_URL . 'assets/' . ltrim($path, '/');
    }

    static function getAssetPath(): string
    {
        return FLUENTCRM_PLUGIN_URL . 'assets/';
    }

    /**
     * Inject Vite client for HMR in development mode
     */
    public static function injectViteClient()
    {
        $vite = static::getInstance();
        
        if ($vite->shouldServeViaDevServer()) {
            $protocol = rtrim($vite->viteHostProtocol, ':/');
            $host = rtrim($vite->viteHost, '/');
            $port = $vite->vitePort;

            // Vite client URL should NOT include /resources/
            $viteClientUrl = sprintf('%s://%s:%s/@vite/client', $protocol, $host, $port);
            echo '<script type="module" crossorigin src="' . esc_url($viteClientUrl) . '"></script>' . "\n";
        }
    }

    /**
     * Print script tag for modules (useful for inline printing)
     */
    public static function printScriptTag($handle, $source, $version = null, $isStatic = false)
    {
        if (empty($version)) {
            $version = FLUENTCRM_PLUGIN_VERSION;
        }

        $vite = static::getInstance();

        if ($isStatic) {
            $srcPath = $vite->getStaticEnqueuePath($source);
        } else {
            if ($vite->shouldServeViaDevServer()) {
                $srcPath = $vite->getVitePath() . $source;
            } else {
                $assetFile = $vite->getFileFromManifest($source);
                $srcPath = $vite->getProductionFilePath($assetFile);
            }
        }

        if (!empty($srcPath)) {
            $version = empty($version) ? FLUENTCRM_PLUGIN_VERSION : $version;
            $type = !$isStatic && in_array($handle, $vite->moduleScripts) ? 'module' : 'text/javascript';

            echo '<script type="' . esc_attr($type) . '" src="' . esc_url($srcPath) . '?ver=' . esc_attr($version) . '" id="' . esc_attr($handle) . '-js"></script>' . "\n";
        }
    }
}
