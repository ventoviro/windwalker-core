<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 LYRASOFT. All rights reserved.
 * @license    GNU General Public License version 2 or later;
 */

namespace Windwalker\Core\Asset;

use JetBrains\PhpStorm\ArrayShape;
use Windwalker\Core\Attributes\Ref;
use Windwalker\Core\Router\SystemUri;
use Windwalker\Event\EventAwareInterface;
use Windwalker\Event\EventAwareTrait;
use Windwalker\Event\EventEmitter;
use Windwalker\Filesystem\Filesystem;
use Windwalker\Filesystem\Path;
use Windwalker\Utilities\Str;

/**
 * The AssetManager class.
 *
 * @since   3.0
 */
class AssetManager implements EventAwareInterface
{
    use EventAwareTrait;

    /**
     * Property styles.
     *
     * @var  AssetLink[]
     */
    protected array $styles = [];

    /**
     * Property scripts.
     *
     * @var  AssetLink[]
     */
    protected array $scripts = [];

    /**
     * Property aliases.
     *
     * @var  AssetLink[]
     */
    protected array $aliases = [];

    /**
     * Property internalStyles.
     *
     * @var  array
     */
    protected array $internalStyles = [];

    /**
     * Property internalScripts.
     *
     * @var  array
     */
    protected array $internalScripts = [];

    /**
     * Property version.
     *
     * @var string
     */
    protected string $version;

    /**
     * Property indents.
     *
     * @var  string
     */
    protected string $indents = '    ';

    /**
     * Property path.
     *
     * @var  string
     */
    public string $path = '';

    /**
     * Property root.
     *
     * @var  string
     */
    public string $root = '';

    protected array $options;

    /**
     * AssetManager constructor.
     *
     * @param  array         $options
     * @param  SystemUri     $systemUri
     * @param  EventEmitter  $dispatcher
     */
    public function __construct(
        #[Ref('asset')] array $options,
        protected SystemUri $systemUri,
        EventEmitter $dispatcher
    ) {
        $this->path = $options['uri'] ?? $this->systemUri->path($options['folder'] ?? 'asset');
        $this->root = $options['uri'] ?? $this->systemUri->root($options['folder'] ?? 'asset');

        $this->dispatcher = $dispatcher;
        $this->options = $options;
    }

    /**
     * addStyle
     *
     * @param string  $url
     * @param array   $options
     * @param array   $attrs
     *
     * @return  static
     */
    public function addCSS(string $url, array $options = [], array $attrs = []): static
    {
        return $this->addLink('styles', $url, $options, $attrs);
    }

    /**
     * addScript
     *
     * @param string  $url
     * @param array   $options
     * @param array   $attrs
     *
     * @return  static
     */
    public function addScript(string $url, array $options = [], array $attrs = []): static
    {
        return $this->addLink('scripts', $url, $options, $attrs);
    }

    public function addLink(string $type, string $url, array $options = [], array $attrs = []): static
    {
        $alias = $this->resolveRawAlias($url);

        $link = $alias ?? new AssetLink('', $this->handleUri($url), $options);

        $link = $link->withOptions($options);

        foreach ($attrs as $name => $value) {
            $link = $link->withAttribute($name, $value);
        }

        $this->$type[$url] = $link;

        return $this;
    }

    /**
     * import
     *
     * @param string $url
     * @param array  $options
     * @param array  $attribs
     *
     * @return  AssetManager
     *
     * @since       3.3
     *
     * @deprecated  HTML imports has been deprecated.
     */
    public function import($url, array $options = [], array $attribs = [])
    {
        if (Arr::get($options, 'as') === 'style') {
            $attribs['rel'] = 'import';

            return $this->addStyle($url, $options, $attribs);
        }

        $options['import'] = true;

        return $this->addScript($url, $options, $attribs);
    }

    /**
     * internalStyle
     *
     * @param string $content
     *
     * @return  static
     */
    public function internalStyle($content)
    {
        $this->internalStyles[] = (string) $content;

        return $this;
    }

    /**
     * internalStyle
     *
     * @param string $content
     *
     * @return  static
     */
    public function internalScript($content)
    {
        $this->internalScripts[] = (string) $content;

        return $this;
    }

    /**
     * Check asset uri exists in system and return actual path.
     *
     * @param string $uri    The file uri to check.
     * @param bool   $strict Check .min file or un-min file exists again if input file not exists.
     *
     * @return  bool|string
     *
     * @since  3.3
     */
    public function exists($uri, $strict = false)
    {
        if (static::isAbsoluteUrl($uri)) {
            return $uri;
        }

        $assetUri = $this->path;

        if (static::isAbsoluteUrl($assetUri)) {
            return rtrim($assetUri, '/') . '/' . ltrim($uri, '/');
        }

        $root = $this->addSysPath($assetUri);

        $this->normalizeUri($uri, $assetFile, $assetMinFile);

        if (is_file($root . '/' . $uri)) {
            return $this->addBase($uri, 'path');
        }

        if (!$strict) {
            if (is_file($root . '/' . $assetFile)) {
                return $this->addBase($assetFile, 'path');
            }

            if (is_file($root . '/' . $assetMinFile)) {
                return $this->addBase($assetMinFile, 'path');
            }
        }

        return false;
    }

    /**
     * renderStyles
     *
     * @param bool  $withInternal
     * @param array $internalAttrs
     *
     * @return string
     */
    public function renderStyles($withInternal = false, array $internalAttrs = [])
    {
        $html = [];

        Ioc::getApplication()->triggerEvent('onAssetRenderStyles', [
            'asset' => $this,
            'withInternal' => &$withInternal,
            'html' => &$html,
        ]);

        foreach ($this->styles as $url => $style) {
            $defaultAttribs = [
                'href' => $style['url'],
                'rel' => 'stylesheet',
            ];

            $attribs = array_merge($defaultAttribs, $style['attribs']);

            if ($style['options']['version'] !== false) {
                $attribs['href'] = $this->appendVersion($attribs['href']);
            }

            if (isset($style['options']['sri'])) {
                $attribs['integrity']   = $style['options']['sri'];
                $attribs['crossorigin'] = 'anonymous';
            }

            if (isset($style['options']['conditional'])) {
                $html[] = '<!--[if ' . $style['options']['conditional'] . ']>';
            }

            $html[] = (string) h('link', $attribs, null);

            if (isset($style['options']['conditional'])) {
                $html[] = '<![endif]-->';
            }
        }

        if ($withInternal && $this->internalStyles) {
            $html[] = (string) h(
                'style',
                $internalAttrs,
                "\n" . $this->renderInternalStyles() . "\n" . $this->indents
            );
        }

        return implode("\n" . $this->indents, $html);
    }

    /**
     * renderStyles
     *
     * @param bool  $withInternal
     * @param array $internalAttrs
     *
     * @return string
     */
    public function renderScripts($withInternal = false, array $internalAttrs = [])
    {
        $html = [];

        $this->triggerEvent('onAssetRenderScripts', [
            'asset' => $this,
            'withInternal' => &$withInternal,
            'html' => &$html,
        ]);

        foreach ($this->scripts as $url => $script) {
            $defaultAttribs = [
                'src' => $script['url'],
            ];

            $attribs = array_merge($defaultAttribs, $script['attribs']);

            if ($script['options']['version'] !== false) {
                $attribs['src'] = $this->appendVersion($attribs['src']);
            }

            if (isset($script['options']['sri'])) {
                $attribs['integrity']   = $script['options']['sri'];
                $attribs['crossorigin'] = 'anonymous';
            }

            if (isset($script['options']['conditional'])) {
                $html[] = '<!--[if ' . $script['options']['conditional'] . ']>';
            }

            if (isset($script['options']['import'])) {
                $attribs['href'] = $attribs['src'];
                $attribs['rel']  = 'import';
                unset($attribs['src']);
                $html[] = (string) h('link', $attribs, null);
            } else {
                if ($script['options']['body'] ?? null) {
                    $content = $script['options']['body'];

                    unset($attribs['src']);
                } else {
                    $content = null;
                }

                $html[] = (string) h('script', $attribs, $content);
            }

            if (isset($script['options']['conditional'])) {
                $html[] = '<![endif]-->';
            }
        }

        if ($withInternal && $this->internalScripts) {
            $html[] = (string) h(
                'script',
                $internalAttrs,
                "\n" . $this->renderInternalScripts() . "\n" . $this->indents
            );
        }

        return implode("\n" . $this->indents, $html);
    }

    /**
     * renderInternalStyles
     *
     * @return  string
     */
    public function renderInternalStyles()
    {
        return implode("\n\n", $this->internalStyles);
    }

    /**
     * renderInternalStyles
     *
     * @return  string
     */
    public function renderInternalScripts()
    {
        return implode(";\n", $this->internalScripts);
    }

    /**
     * getVersion
     *
     * @return  string
     */
    public function getVersion()
    {
        if ($this->version) {
            return $this->version;
        }

        if ($this->config->get('system.debug')) {
            return $this->version = md5(uniqid('Windwalker-Asset-Version', true));
        }

        $sumFile = $this->config->get('path.cache') . '/asset/MD5SUM';

        if (!is_file($sumFile)) {
            return $this->version = $this->detectVersion();
        }

        return $this->version = trim(file_get_contents($sumFile));
    }

    /**
     * appendVersion
     *
     * @param string $uri
     * @param string $version
     *
     * @return  string
     *
     * @since  3.4.9.3
     */
    public function appendVersion($uri, $version = null)
    {
        $version = $version ?: $this->getVersion();

        if (!$version) {
            return $uri;
        }

        $sep = strpos($uri, '?') !== false ? '&' : '?';

        return $uri . $sep . $version;
    }

    /**
     * detectVersion
     *
     * @return  string
     */
    protected function detectVersion()
    {
        static $version;

        if ($version) {
            return $version;
        }

        $assetUri = $this->path;

        if (static::isAbsoluteUrl($assetUri)) {
            return $version = md5($assetUri . $this->config->get('system.secret', 'Windwalker-Asset'));
        }

        $time  = '';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->addSysPath($assetUri),
                \FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isLink() || $file->isDir()) {
                continue;
            }

            $time .= $file->getMTime();
        }

        return $version = md5($this->config->get('system.secret', 'Windwalker-Asset') . $time);
    }

    /**
     * removeBase
     *
     * @param   string $assetUri
     *
     * @return  string
     */
    public function addSysPath($assetUri)
    {
        if (static::isAbsoluteUrl($assetUri)) {
            return $assetUri;
        }

        $assetUri = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $assetUri), '/\\');
        $base     = rtrim($this->config->get('path.public'), '/\\');

        if (!$base) {
            return '/';
        }

        $match = '';

        // @see http://stackoverflow.com/a/6704596
        for ($i = strlen($base) - 1; $i >= 0; $i -= 1) {
            $chunk = substr($base, $i);
            $len   = strlen($chunk);

            if (substr($assetUri, 0, $len) == $chunk && $len > strlen($match)) {
                $match = $chunk;
            }
        }

        return $base . DIRECTORY_SEPARATOR . ltrim(substr($assetUri, strlen($match)), '/\\');
    }

    /**
     * isAbsoluteUrl
     *
     * @param   string $uri
     *
     * @return  boolean
     */
    public static function isAbsoluteUrl($uri)
    {
        return stripos($uri, 'http') === 0 || strpos($uri, '//') === 0;
    }

    /**
     * Method to set property version
     *
     * @param   string $version
     *
     * @return  static  Return self to support chaining.
     */
    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Method to get property Styles
     *
     * @return  array
     */
    public function getStyles()
    {
        return $this->styles;
    }

    /**
     * Method to set property styles
     *
     * @param   array $styles
     *
     * @return  static  Return self to support chaining.
     */
    public function setStyles($styles)
    {
        $this->styles = $styles;

        return $this;
    }

    /**
     * Method to get property Scripts
     *
     * @return  array
     */
    public function &getScripts()
    {
        return $this->scripts;
    }

    /**
     * Method to set property scripts
     *
     * @param   array $scripts
     *
     * @return  static  Return self to support chaining.
     */
    public function setScripts($scripts)
    {
        $this->scripts = $scripts;

        return $this;
    }

    /**
     * Method to get property InternalStyles
     *
     * @return  array
     */
    public function getInternalStyles()
    {
        return $this->internalStyles;
    }

    /**
     * Method to set property internalStyles
     *
     * @param   array $internalStyles
     *
     * @return  static  Return self to support chaining.
     */
    public function setInternalStyles($internalStyles)
    {
        $this->internalStyles = $internalStyles;

        return $this;
    }

    /**
     * Method to get property InternalScripts
     *
     * @return  array
     */
    public function getInternalScripts()
    {
        return $this->internalScripts;
    }

    /**
     * Method to set property internalScripts
     *
     * @param   array $internalScripts
     *
     * @return  static  Return self to support chaining.
     */
    public function setInternalScripts($internalScripts)
    {
        $this->internalScripts = $internalScripts;

        return $this;
    }

    /**
     * alias
     *
     * @param string $target
     * @param string $alias
     * @param array  $options
     * @param array  $attrs
     *
     * @return  static
     */
    public function alias(string $target, string $alias, array $options = [], array $attrs = []): static
    {
        $this->normalizeUri($target, $name);

        $link = new AssetLink($alias, $options);
        $link = $link->withAttributes($attrs);

        $this->aliases[$name] = $link;

        return $this;
    }

    /**
     * resolveAlias
     *
     * @param   string $uri
     *
     * @return  string
     */
    public function resolveAlias(string $uri): string
    {
        $this->normalizeUri($uri, $name);

        return $this->resolveRawAlias($uri)['alias'] ?? $uri;
    }

    /**
     * resolveRawAlias
     *
     * @param string $uri
     *
     * @return  array|null
     *
     * @since  3.5.5
     */
    public function resolveRawAlias(string $uri): ?AssetLink
    {
        $this->normalizeUri($uri, $name);

        while (isset($this->aliases[$name])) {
            $alias = $this->aliases[$name];
            $name = $alias->getHref();
        }

        return $alias ?? null;
    }

    /**
     * Method to set property indents
     *
     * @param   string $indents
     *
     * @return  static  Return self to support chaining.
     */
    public function setIndents(string $indents): static
    {
        $this->indents = $indents;

        return $this;
    }

    /**
     * Method to get property Indents
     *
     * @return  string
     */
    public function getIndents()
    {
        return $this->indents;
    }

    /**
     * handleUri
     *
     * @param   string $uri
     *
     * @return  string
     */
    public function handleUri($uri)
    {
        $uri = $this->resolveAlias($uri);

        // Check has .min
        // $uri = Uri::addBase($uri, 'path');

        if (static::isAbsoluteUrl($uri)) {
            return $uri;
        }

        $assetUri = $this->path;

        if (static::isAbsoluteUrl($assetUri)) {
            return rtrim($assetUri, '/') . '/' . ltrim($uri, '/');
        }

        $root = $this->addSysPath($assetUri);

        $this->normalizeUri($uri, $assetFile, $assetMinFile);

        // Use uncompressed file first
        if ($this->config->get('system.debug')) {
            if (is_file($root . '/' . $assetFile)) {
                return $this->addBase($assetFile, 'path');
            }

            if (is_file($root . '/' . $assetMinFile)) {
                return $this->addBase($assetMinFile, 'path');
            }
        } else {
            // Use min file first
            if (is_file($root . '/' . $assetMinFile)) {
                return $this->addBase($assetMinFile, 'path');
            }

            if (is_file($root . '/' . $assetFile)) {
                return $this->addBase($assetFile, 'path');
            }
        }

        // All file not found, fallback to default uri.
        return $this->addBase($uri, 'path');
    }

    /**
     * normalizeUri
     *
     * @param  string       $uri
     * @param  string|null  $assetFile
     * @param  string|null  $assetMinFile
     *
     * @return  array
     */
    #[ArrayShape(['string', 'string'])]
    public function normalizeUri(string $uri, ?string &$assetFile = null, ?string &$assetMinFile = null): array
    {
        $ext = Path::getExtension($uri);

        if (Str::endsWith($uri, '.min.' . $ext)) {
            $assetFile    = substr($uri, 0, -strlen('.min.' . $ext)) . '.' . $ext;
            $assetMinFile = $uri;
        } else {
            $assetMinFile = substr($uri, 0, -strlen('.' . $ext)) . '.min.' . $ext;
            $assetFile    = $uri;
        }

        return [$assetFile, $assetMinFile];
    }

    /**
     * addBase
     *
     * @param string $uri
     * @param string $path
     *
     * @return  string
     */
    public function addBase($uri, $path = 'path')
    {
        if (!static::isAbsoluteUrl($uri)) {
            $uri = $this->$path . '/' . $uri;
        }

        return $uri;
    }

    /**
     * addUriBase
     *
     * @param string $uri
     * @param string $path
     *
     * @return  mixed|string
     *
     * @since  3.5.22.6
     */
    public function addUriBase($uri, $path = 'path')
    {
        if (!static::isAbsoluteUrl($uri)) {
            $uri = $this->uri->$path . '/' . $uri;
        }

        return $uri;
    }

    /**
     * Method to get property Template
     *
     * @return  AssetTemplate
     */
    public function getTemplate()
    {
        if (!$this->template) {
            return $this->template = new AssetTemplate();
        }

        return $this->template;
    }

    /**
     * Method to set property template
     *
     * @param   AssetTemplate $template
     *
     * @return  static  Return self to support chaining.
     */
    public function setTemplate(AssetTemplate $template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * __call
     *
     * @param   string $name
     * @param   array  $args
     *
     * @return  mixed
     */
    public function __call($name, $args)
    {
        switch ($name) {
            case 'addCSS':
                return $this->addStyle(...$args);
                break;

            case 'addJS':
                return $this->addScript(...$args);
                break;

            case 'internalCSS':
                return $this->internalStyle(...$args);
                break;

            case 'internalJS':
                return $this->internalScript(...$args);
                break;
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s() of %s', $name, get_class($this)));
    }

    /**
     * Internal method to get a JavaScript object notation string from an array
     *
     * @param mixed $data     The data to convert to JavaScript object notation
     * @param bool  $quoteKey Quote json key or not.
     *
     * @return string JavaScript object notation representation of the array
     */
    public static function getJSObject($data, $quoteKey = false)
    {
        if ($data === null) {
            return 'null';
        }

        if ($data instanceof RawWrapper) {
            return $data->get();
        }

        $output = '';

        switch (gettype($data)) {
            case 'boolean':
                $output .= $data ? 'true' : 'false';
                break;

            case 'float':
            case 'double':
            case 'integer':
                $output .= $data + 0;
                break;

            case 'array':
                if (!Arr::isAssociative($data)) {
                    $child = [];

                    foreach ($data as $value) {
                        $child[] = static::getJSObject($value, $quoteKey);
                    }

                    $output .= '[' . implode(',', $child) . ']';
                    break;
                }
                // No break
            case 'object':
                $array = is_object($data) ? get_object_vars($data) : $data;

                $row = [];

                foreach ($array as $key => $value) {
                    $encodedKey = json_encode((string) $key);

                    if (!$quoteKey && preg_match('/[^0-9A-Za-z_]+/m', $key) == 0) {
                        $encodedKey = substr(substr($encodedKey, 0, -1), 1);
                    }

                    $row[] = $encodedKey . ':' . static::getJSObject($value, $quoteKey);
                }

                $output .= '{' . implode(',', $row) . '}';
                break;

            default:  // anything else is treated as a string
                return strpos($data, '\\') === 0 ? substr($data, 1) : json_encode($data);
                break;
        }

        return $output;
    }

    /**
     * Method to get property Path
     *
     * @param string $uri
     * @param bool   $version
     *
     * @return string
     */
    public function path($uri = null, $version = false)
    {
        if ($version === true) {
            $version = $this->getVersion();
        }

        if ($version) {
            if (strpos($uri, '?') !== false) {
                $uri .= '&' . $version;
            } else {
                $uri .= '?' . $version;
            }
        }

        if ($uri !== null) {
            return $this->path . '/' . $uri;
        }

        return $this->path;
    }

    /**
     * Method to get property Root
     *
     * @param  string $uri
     * @param  bool   $version
     *
     * @return string
     */
    public function root($uri = null, $version = false)
    {
        if ($version === true) {
            $version = $this->getVersion();
        }

        if ($version) {
            if (strpos($uri, '?') !== false) {
                $uri .= '&' . $version;
            } else {
                $uri .= '?' . $version;
            }
        }

        if ($uri !== null) {
            return $this->root . '/' . $uri;
        }

        return $this->root;
    }

    /**
     * Method to get property AssetFolder
     *
     * @return  string
     */
    public function getAssetFolder()
    {
        return $this->config->get('asset.folder', 'asset');
    }

    /**
     * Method to set property uri
     *
     * @param   UriData $uri
     *
     * @return  static  Return self to support chaining.
     */
    public function setUriData(UriData $uri)
    {
        $this->uri = $uri;

        return $this;
    }

    /**
     * __get
     *
     * @param   string $name
     *
     * @return  mixed
     */
    public function __get($name)
    {
        $allow = [
            'uri',
        ];

        if (in_array($name, $allow)) {
            return $this->$name;
        }

        throw new \OutOfRangeException(sprintf('Property %s not exists.', $name));
    }

    /**
     * reset
     *
     * @return  static
     *
     * @since  3.5.13
     */
    public function reset(): self
    {
        $this->styles = [];
        $this->scripts = [];
        $this->internalStyles = [];
        $this->internalScripts = [];

        return $this;
    }
}
