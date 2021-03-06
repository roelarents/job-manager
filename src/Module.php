<?php

namespace Bvarent\JobManager;

use Zend\Config\Config;
use Zend\Console\Adapter\AdapterInterface;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ConsoleBannerProviderInterface;
use Zend\ModuleManager\Feature\ConsoleUsageProviderInterface;
use Zend\ModuleManager\Feature\DependencyIndicatorInterface;
use Zend\ModuleManager\Feature\ServiceProviderInterface;
use Zend\ModuleManager\ModuleEvent;
use Zend\ModuleManager\ModuleManagerInterface;

class Module implements AutoloaderProviderInterface, ConfigProviderInterface, ServiceProviderInterface, DependencyIndicatorInterface, ConsoleBannerProviderInterface, ConsoleUsageProviderInterface
{
    /**
     * A human readable name for this module.
     */
    const MODULE_NAME = 'Job Manager';
    
    /**
     * The key to use in the global ZF2 config to identify this module.
     */
    const CONFIG_KEY = 'bvarent-jobmanager';

    /**
     * Gives the path to the root directory of this module.
     * @return string
     */
    protected function getModulePath()
    {
        // Assume this file is in {module root path}/src.
        return dirname(__DIR__);
    }
    
    public function getAutoloaderConfig()
    {
        // Composer probably takes care of the autoloading. But just in case:
        return [
            'Zend\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__,
                ],
            ],
        ];
    }

    public function getModuleDependencies()
    {
        return [
            'DoctrineModule',
            'DoctrineORMModule',
        ];
    }

    public function getConfig()
    {
        return include dirname(__DIR__) . '/config/module.config.php';
    }

    public function getServiceConfig()
    {
        return include dirname(__DIR__) . '/config/service.config.php';
    }
    
    /**
     * Looks up configured names of doctrine services for some entity manager to use.
     * @param Config $config
     * @param string $entityManagerName
     * @return Map<string, string> [
     *  entitymanager => ... ,
     *  configuration => ... ,
     *  connection => ... ,
     *  driver => ... ]
     */
    protected function getDoctrineServiceNamesFromEntityManager(Config & $config, $entityManagerName = 'orm_default')
    {
        $entityManagerName = !empty($entityManagerName) ? $entityManagerName : 'orm_default';
        $defaultServiceName = $entityManagerName;
        $emptyConfig = new Config([]);
        $doctrineConfig = $config->get('doctrine', $emptyConfig);
        $doctrineServiceNames = [];
        
        // Take over entitymanager name.
        $doctrineServiceNames['entitymanager'] = $entityManagerName;
        
        // Find connection and configuration names in said entitymanager's config.
        $entityManagerConfig = $doctrineConfig->get('entitymanager', $emptyConfig)->get($entityManagerName, $emptyConfig);
        $doctrineServiceNames['connection'] = $entityManagerConfig->get('connection', $defaultServiceName);
        $doctrineServiceNames['configuration'] = $entityManagerConfig->get('configuration', $defaultServiceName);
        
        // Find the driver in said configuration's config.
        $configurationConfig = $doctrineConfig->get('configuration', $emptyConfig)->get($doctrineServiceNames['configuration'], $emptyConfig);
        $doctrineServiceNames['driver'] = $configurationConfig->get('driver', $defaultServiceName);
        
        return $doctrineServiceNames;
    }

    /**
     * Gets the configuration extensions for the Doctrine (ORM) Module.
     * @param Config $totalConfig A config object which contains [module_config_key]['doctrine_service_names'].
     * @return Config
     */
    protected function getDoctrineConfig(Config $totalConfig)
    {
        $modulePath = $this->getModulePath();
        
        // Get doctrine services names to use. This variable is used by the included config file.
        $doctrineServiceNames = $this->getDoctrineServiceNamesFromEntityManager($totalConfig, $totalConfig[static::CONFIG_KEY]['entitymanager']);

        $doctrineConfigArray = include $modulePath . '/config/doctrine.config.php';
        $doctrineConfig = new Config(['doctrine' => $doctrineConfigArray]);
        
        return $doctrineConfig;
    }
    
    /**
     * Merges our Doctrine config extensions into the existing total config.
     * @param Config $totalConfig The total config after merging by the ModuleManager.
     */
    protected function mergeInDoctrineConfig(Config & $totalConfig)
    {
        $doctrineConfig = $this->getDoctrineConfig($totalConfig);
        $totalConfig->merge($doctrineConfig);
    }
    
    /**
     * Performs actions upon the total config, when the ConfigListener is done merging.
     * @param ModuleEvent $e
     */
    public function onMergeConfig(ModuleEvent $e)
    {
        // Retrieve the config.
        $configListener = $e->getConfigListener();
        $totalConfig         = $configListener->getMergedConfig();
        /* @var $totalConfig Config */
        
        // Perform custom actions.
        $this->mergeInDoctrineConfig($totalConfig);

        // Pass the changed configuration back to the listener.
        $configListener->setMergedConfig($totalConfig->toArray());
    }

    public function init(ModuleManagerInterface $moduleManager)
    {
        // Bind our 'onMergeConfig' method to the 'mergeConfig' event.
        $events = $moduleManager->getEventManager();
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, [$this, 'onMergeConfig']);
    }

    public function getConsoleBanner(AdapterInterface $console)
    {
        return static::MODULE_NAME;
    }

    public function getConsoleUsage(AdapterInterface $console)
    {
        return [
            Module::CONFIG_KEY . ' end-coma-jobs [--signal=] [<type>]' => 'Mark timed out jobs as ended by setting their success parameter to false.',
            ["--signal", "(Optional) Send this (kill) signal to the job's process. WARNING: The PID might not belong to the running script/job anymore. Also you might not have enough permissions to kill the process."],
            ["<type>", "(Optional) Only consider job records of this type. Specify the full entity class or the discriminator name."],
            
            Module::CONFIG_KEY . ' delete-old-jobs --age= [<type>]' => 'Delete old, finalized, jobs from the log.',
            ["--age", "Jobs must be at least this old. Specified as an ISO_8601 duration. E.g. 'P2M' for 2 months and older."],
            ["<type>", "(Optional) Only consider job records of this type. Specify the full entity class or the discriminator name."],
        ];
    }
}
