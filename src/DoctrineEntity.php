<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Config;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedYamlDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\PHPDriver;

//use Doctrine\Common\Cache;

class DoctrineEntity
{
    private Configuration $_config;
    //    private object $_cache;

    private string $relativeDirPath;

    /**
     * Constructor
     *
     * @param string ...$domain
     * @throws \Doctrine\DBAL\Exception
     */
    public function __construct(string ...$domain)
    {
        $this->relativeDirPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
        $this->dbalTypes();
        $this->_onInit($domain);
    }

    /**
     * Set default configs for
     * cache and proxy with production mode.
     *
     * @param array $domain
     * @return void
     */
    private function _onInit(array $domain): void
    {
        $config = new Configuration();
        $enableProdMode = false;

        $configFilePath = $this->relativeDirPath . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        // get values from config
        if (file_exists($configFilePath)) {
            include_once $configFilePath;
            $enableProdMode = (new Config())->enableProdMode;
        }

        // Set defaults
        if ($enableProdMode) {
            // set default to prod mode
            // change the cache to Redis
            // declare(strict_types=1);
            // use Doctrine\Common\Cache\RedisCache;
            // use Doctrine\ORM\Configuration;

            // $metadataCache = new RedisCache();
            // $configuration = new Configuration();
            // ...
            // $configuration->setMetadataCacheImpl($metadataCache);
            //            $cache = new MemcachedCache();
            $config->setAutoGenerateProxyClasses(DoctrineConfig['doctrine']['orm']['generate_proxy_classes'] ?? false);
            //            make sure to use redis
        } else {
            // set default to dev mode

            //            $cache = new ArrayCache();
            //            $config->setMetadataCache($cache);
            $config->setAutoGenerateProxyClasses(true);
        }
        // echo __DIR__;
        $domainRootPath = $this->relativeDirPath . 'src' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR;

        // I might need to force value of driver for domain folder at constructor
        // Driver Implementation
        //        $driverImpl = $config
        //            ->newDefaultAnnotationDriver($domainRootPath . $domain);
        //        use attribute(meta) as default

        $domainPath = [];
        foreach ($domain as $i => $iValue) {
            $domainPath[$i] = $domainRootPath . ucfirst($iValue);
        }

        //        MetaDataDriverImplementation
        $driverImpl = match (DoctrineConfig['doctrine']['orm']['metadata_driver_implementation'] ?? 'attribute') {
            'xml' => new XmlDriver($domainPath),
            'annotation' => new AnnotationDriver($domainPath[0]),
            'yaml' => new SimplifiedYamlDriver($domainPath),
            'php' => new PHPDriver($domainPath),
            default => new AttributeDriver($domainPath)
        };


        $config->setMetadataDriverImpl($driverImpl);

        // Cache
        //        $config->setMetadataCacheImpl($cache);
        //        $config->setQueryCacheImpl($cache);

        // Proxies
        $proxyDir = DoctrineConfig['doctrine']['orm']['proxy_dir'] . '/' . $domain[0] ??
            'var/cache/' . ($enableProdMode ? 'prod' : 'dev') . '/doctrine/orm/Proxies/' . $domain[0];
        $proxyNamespace = DoctrineConfig['doctrine']['orm']['proxy_namespace'] ?? 'Proxies';

        $config->setProxyDir($this->relativeDirPath . $proxyDir);
        $config->setProxyNamespace($proxyNamespace);

        $this->_config = $this->_config = $this->setOrmConfigs($config);;
        //        $this->_cache = $cache;
        // return $this;
    }


    /**
     * @return \Doctrine\ORM\Configuration
     */
    public function getDoctrineConfig(): Configuration
    {
        return $this->_config;
    }

    /**
     * @param \Doctrine\ORM\Configuration $config
     * @return $this
     */
    public function setDoctrineConfig(Configuration $config): self
    {
        $this->_config = $config;
        return $this;
    }

    /**
     * Return Doctrine's
     * EntityManager based on the connection string
     *
     * @param array|Connection $connection
     * @param \Doctrine\ORM\Configuration|null $config
     * @return EntityManager
     * @throws \Doctrine\ORM\ORMException
     */
    public
    function entityManager(
        array|Connection $connection,
        ?Configuration $config = null
    ): EntityManager {
        return EntityManager::create($connection, $config ?? $this->_config);
    }


    /**
     * Set Development True/False
     *
     * @param boolean $dev
     * @return self
     */
    public function isDev(bool $dev = false): self
    {
        if ($dev) {
            //            $this->_cache = new ArrayCache;
            $this->_config->setAutoGenerateProxyClasses(true);
        } else {
            //            $this->_cache = new MemcachedCache();
            $this->_config->setAutoGenerateProxyClasses(false);
        }
        return $this;
    }

    // Proxies Directory

    /**
     * Configuration Options
     * The following sections describe all the configuration options
     * available on a Doctrine\ORM\Configuration instance.
     *
     * @param string|null $dir
     * @return self
     */
    public function setProxyDir(?string $dir): self
    {
        if ($dir === null) {
            $dir = $this->relativeDirPath . 'src' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'domain' . DIRECTORY_SEPARATOR . 'proxies';
        }
        // print_r($this->_config);
        $this->_config->setProxyDir($dir);
        return $this;
    }


    // Proxy Namespace

    /**
     * Sets the namespace to use for generated proxy classes.
     *
     * @param string $namespace
     * @return self
     */
    public function setProxyNamespace(string $namespace = 'Core\Domain'): self
    {
        $this->_config->setProxyNamespace($namespace);
        return $this;
    }


    /**
     * Sets the metadata driver implementation that is used
     * by Doctrine to acquire the object-relational
     * metadata for your classes
     *
     * @param [type] $connectionOptions
     * @return self
     */
    public function setMetadataDriverImpl($driver): self
    {
        $this->_config->setMetadataDriverImpl($driver);
        return $this;
    }

    /**
     * Dbal Types
     * @throws \Doctrine\DBAL\Exception
     */
    private function dbalTypes(): void
    {
        $dbalTypes = DoctrineConfig['doctrine']['dbal']['types'];
        if ($dbalTypes !== null && count($dbalTypes) > 0) {
            foreach ($dbalTypes as $types => $value) {
                Type::addType($types, $value);
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param Configuration $doctrineConfig
     * @return Configuration
     */
    private function setOrmConfigs(Configuration $doctrineConfig): Configuration
    {
        $ormConfigs = DoctrineConfig['doctrine']['orm'];
        if ($ormConfigs === null || !is_array($ormConfigs)) {
            return $doctrineConfig;
        }

        //        check for dqls - addCustomFunctions to DQL
        if (array_key_exists('dql', $ormConfigs)) {
            foreach ($ormConfigs['dql'] as $dqlConfig => $valueType) {
                switch ($dqlConfig) {
                    case 'datetime_functions':
                        foreach ($valueType as $type => $value) {
                            $doctrineConfig->addCustomDatetimeFunction($type, $value);
                        }
                        break;

                    case 'numeric_functions':
                        foreach ($valueType as $type => $value) {
                            $doctrineConfig->addCustomNumericFunction($type, $value);
                        }
                        break;

                    case 'string_functions':
                        foreach ($valueType as $type => $value) {
                            $doctrineConfig->addCustomStringFunction($type, $value);
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        return $doctrineConfig;
    }
}
