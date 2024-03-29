<?php
declare(strict_types=1);

namespace Dufry\DynamicTranslation\Framework;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;

/**
 * Translate library
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Translate extends \Magento\Framework\Translate implements \Magento\Framework\TranslateInterface
{
    const CONFIG_CUSTOM_MODULE_KEY = 'wwdt';

    private DriverInterface $fileDriver;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private \Magento\Framework\Serialize\SerializerInterface $serializer;


    /**
     * @param \Magento\Framework\View\DesignInterface $viewDesign
     * @param \Magento\Framework\Cache\FrontendInterface $cache
     * @param \Magento\Framework\View\FileSystem $viewFileSystem
     * @param \Magento\Framework\Module\ModuleList $moduleList
     * @param \Magento\Framework\Module\Dir\Reader $modulesReader
     * @param \Magento\Framework\App\ScopeResolverInterface $scopeResolver
     * @param \Magento\Framework\Translate\ResourceInterface $translate
     * @param \Magento\Framework\Locale\ResolverInterface $locale
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Mecodeninja\DynamicTranslation\Framework\File\Csv $csvParser
     * @param \Magento\Framework\App\Language\Dictionary $packDictionary
     * @param DriverInterface|null $fileDriver
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\View\DesignInterface $viewDesign,
        \Magento\Framework\Cache\FrontendInterface $cache,
        \Magento\Framework\View\FileSystem $viewFileSystem,
        \Magento\Framework\Module\ModuleList $moduleList,
        \Magento\Framework\Module\Dir\Reader $modulesReader,
        \Magento\Framework\App\ScopeResolverInterface $scopeResolver,
        \Magento\Framework\Translate\ResourceInterface $translate,
        \Magento\Framework\Locale\ResolverInterface $locale,
        \Magento\Framework\App\State $appState,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\App\RequestInterface $request,
        \Mecodeninja\DynamicTranslation\Framework\File\Csv $csvParser,
        \Magento\Framework\App\Language\Dictionary $packDictionary,
        DriverInterface $fileDriver = null
    ) {
        $this->_viewDesign = $viewDesign;
        $this->_cache = $cache;
        $this->_viewFileSystem = $viewFileSystem;
        $this->_moduleList = $moduleList;
        $this->_modulesReader = $modulesReader;
        $this->_scopeResolver = $scopeResolver;
        $this->_translateResource = $translate;
        $this->_locale = $locale;
        $this->_appState = $appState;
        $this->request = $request;
        $this->directory = $filesystem->getDirectoryRead(DirectoryList::ROOT);
        $this->_csvParser = $csvParser;
        $this->packDictionary = $packDictionary;
        $this->fileDriver = $fileDriver
            ?? ObjectManager::getInstance()->get(File::class);

        $this->_config = [
            self::CONFIG_AREA_KEY => null,
            self::CONFIG_LOCALE_KEY => null,
            self::CONFIG_SCOPE_KEY => null,
            self::CONFIG_THEME_KEY => null,
            self::CONFIG_MODULE_KEY => null,
        ];

        parent::__construct(
            $viewDesign,
            $cache,
            $viewFileSystem,
            $moduleList,
            $modulesReader,
            $scopeResolver,
            $translate,
            $locale,
            $appState,
            $filesystem,
            $request,
            $csvParser,
            $packDictionary,
            $fileDriver
        );
    }

    /**
     * Initialize translation data
     *
     * @param string|null $area
     * @param bool $forceReload
     * @return $this
     * @throws LocalizedException
     */
    public function loadData($area = null, $forceReload = false)
    {
        $this->_data = [];
        if ($area === null) {
            $area = $this->_appState->getAreaCode();
        }
        $this->setConfig(
            [
                self::CONFIG_AREA_KEY => $area,
            ]
        );

        if (!$forceReload) {
            $data = $this->_loadCache();
            if (false !== $data) {
                $this->_data = $data;
                return $this;
            }
        }

        $this->_loadModuleTranslation();
        $this->_loadPackTranslation();
        $this->_loadThemeTranslation();

        if (!$forceReload) {
            $this->_saveCache();
        }

        return $this;
    }

    /**
     * Retrieve data from file
     *
     * @param string $file
     * @return array
     * @throws FileSystemException
     * @throws \Exception
     */
    protected function _getFileData($file)
    {
        $data = [];
        if ($this->fileDriver->isExists($file)) {
            $this->_csvParser->setDelimiter(',');
            $data = $this->_csvParser->getDataPairsExtended($file);
        }
        return $data;
    }

    /**
     * Loading data cache
     *
     * @return array|bool
     */
    protected function _loadCache()
    {
        $data = $this->_cache->load($this->getCacheId());
        if ($data) {
            $data = $this->getSerializer()->unserialize($data);
        }
        return $data;
    }

    /**
     * Set locale
     *
     * @param string $locale
     * @return \Magento\Framework\TranslateInterface
     */

    protected function _loadThemeTranslation()
    {
        $themeFiles = $this->getThemeTranslationFilesList($this->getLocale());

        /** @var string $file */
        foreach ($themeFiles as $file) {
            if ($file) {
                $this->_addData($this->_getFileData($file));
            }
        }

        return $this;
    }

    /**
     * Saving data cache
     *
     * @return $this
     */
    protected function _saveCache()
    {
        $this->_cache->save($this->getSerializer()->serialize($this->getData()), $this->getCacheId(), [], false);
        return $this;
    }

    /**
     * Retrieve cache identifier
     *
     * @return string
     */
    protected function getCacheId()
    {
        $_cacheId = \Magento\Framework\App\Cache\Type\Translate::TYPE_IDENTIFIER;
        $_cacheId .= '_' . $this->_config[self::CONFIG_LOCALE_KEY];
        $_cacheId .= '_' . $this->_config[self::CONFIG_AREA_KEY];
        $_cacheId .= '_' . $this->_config[self::CONFIG_SCOPE_KEY];
        $_cacheId .= '_' . $this->_config[self::CONFIG_THEME_KEY];
        $_cacheId .= '_' . $this->_config[self::CONFIG_MODULE_KEY];
        $_cacheId .= '_' . $this->_config[self::CONFIG_CUSTOM_MODULE_KEY];

        $this->_cacheId = $_cacheId;
        return $_cacheId;
    }

    /**
     * Get serializer
     *
     * @return \Magento\Framework\Serialize\SerializerInterface
     * @deprecated 101.0.0
     */
    private function getSerializer()
    {
        if ($this->serializer === null) {
            $this->serializer = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Serialize\SerializerInterface::class);
        }
        return $this->serializer;
    }

    /**
     * Load data from module translation files by list of modules
     *
     * @param array $modules
     * @return $this
     * @throws FileSystemException
     */
    protected function loadModuleTranslationByModulesList(array $modules)
    {
        foreach ($modules as $module) {
            $moduleFilePath = $this->_getModuleTranslationFile($module, $this->getLocale());
            $this->_addData($this->_getFileData($moduleFilePath));
        }
        return $this;
    }

    /**
     * Get parent themes for the current theme in fallback order
     *
     * @return array
     */
    private function getParentThemesList(): array
    {
        $themes = [];

        $parentTheme = $this->_viewDesign->getDesignTheme()->getParentTheme();
        while ($parentTheme) {
            $themes[] = $parentTheme;
            $parentTheme = $parentTheme->getParentTheme();
        }

        return array_reverse($themes);
    }

    /**
     * Get theme translation locale file name
     *
     * @param string|null $locale
     * @param array $config
     * @return string|null
     */
    private function getThemeTranslationFileName(?string $locale, array $config): ?string
    {
        $fileName = $this->_viewFileSystem->getLocaleFileName(
            'i18n' . '/' . $locale . '.csv',
            $config
        );

        return $fileName ? $fileName : null;
    }

    /**
     * Retrieve translation files for themes according to fallback
     *
     * @param string $locale
     *
     * @return array
     */
    private function getThemeTranslationFilesList($locale): array
    {
        $translationFiles = [];

        /** @var \Magento\Framework\View\Design\ThemeInterface $theme */
        foreach ($this->getParentThemesList() as $theme) {
            $config = $this->_config;
            $config['theme'] = $theme->getCode();
            $translationFiles[] = $this->getThemeTranslationFileName($locale, $config);
        }

        $translationFiles[] = $this->getThemeTranslationFileName($locale, $this->_config);

        return $translationFiles;
    }
}
