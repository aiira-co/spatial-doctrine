<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedYamlDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\PHPDriver;
use Spatial\Entity\Cache\DoctrineCacheFactory;

class DoctrineEntity
{
    private Configuration $config;
    private readonly string $rootPath;
    private readonly string $domainRootPath;

    /**
     * @param string ...$domains Domain names to configure
     * @throws \Doctrine\DBAL\Exception
     */
    public function __construct(string ...$domains)
    {
        // Cache paths
        $this->rootPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;
        $this->domainRootPath = $this->rootPath . 'src' . DIRECTORY_SEPARATOR
            . 'core' . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR;

        $this->registerDbalTypes();
        $this->config = $this->createConfiguration($domains);
    }

    /**
     * Get Doctrine ORM configuration
     */
    public function getDoctrineConfig(): Configuration
    {
        return $this->config;
    }

    /**
     * Set custom Doctrine configuration
     */
    public function setDoctrineConfig(Configuration $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Create EntityManager with given connection parameters
     *
     * @param array<string, mixed>|Connection $connectionParams
     * @throws ORMException
     */
    public function entityManager(
        array|Connection $connectionParams,
        ?Configuration $config = null
    ): EntityManagerInterface {
        $connection = $this->createConnection($connectionParams, $config);
        return new EntityManager($connection, $config ?? $this->config);
    }

    /**
     * Create database connection
     *
     * @param array<string, mixed>|Connection $connectionParams
     */
    public function connection(
        array|Connection $connectionParams,
        ?Configuration $config = null
    ): Connection {
        return $this->createConnection($connectionParams, $config);
    }

    /**
     * Toggle development mode
     */
    public function isDev(bool $dev = false): self
    {
        $this->config->setAutoGenerateProxyClasses(
            $dev ? ProxyFactory::AUTOGENERATE_ALWAYS : ProxyFactory::AUTOGENERATE_NEVER
        );
        return $this;
    }

    /**
     * Set custom proxy directory
     */
    public function setProxyDir(?string $dir = null): self
    {
        $dir ??= $this->domainRootPath . 'proxies';
        $this->config->setProxyDir($dir);
        return $this;
    }

    /**
     * Set proxy namespace
     */
    public function setProxyNamespace(string $namespace = 'Core\Domain'): self
    {
        $this->config->setProxyNamespace($namespace);
        return $this;
    }

    /**
     * Set metadata driver implementation
     */
    public function setMetadataDriverImpl(MappingDriver $driver): self
    {
        $this->config->setMetadataDriverImpl($driver);
        return $this;
    }

    /**
     * Create and configure Doctrine Configuration
     *
     * @param array<int, string> $domains
     */
    private function createConfiguration(array $domains): Configuration
    {
        $config = new Configuration();

        // Enable PHP 8.4 native lazy objects - no disk-based proxies needed
        $config->enableNativeLazyObjects(true);
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_NEVER);

        // Set metadata driver
        $config->setMetadataDriverImpl($this->createMetadataDriver($domains));

        // Configure proxy settings
        $this->configureProxies($config, $domains[0] ?? 'default');

        // Add custom DQL functions
        $this->registerCustomDqlFunctions($config);

        $this->configureCaches($config);

        return $config;
    }

    /**
     * Create metadata driver based on configuration
     *
     * @param array<int, string> $domains
     */
    private function createMetadataDriver(array $domains): MappingDriver
    {
        $domainPaths = array_map(
            fn(string $domain) => $this->domainRootPath . ucfirst($domain),
            $domains
        );

        $driverType = DoctrineConfig['doctrine']['orm']['metadata_driver_implementation'] ?? 'attribute';

        return match ($driverType) {
            'xml' => new XmlDriver($domainPaths),
            'yaml' => new SimplifiedYamlDriver($domainPaths),
            'php' => new PHPDriver($domainPaths),
            default => new AttributeDriver($domainPaths)
        };
    }

    /**
     * Configure proxy directory and namespace
     */
    private function configureProxies(Configuration $config, string $domain): void
    {
        $enableProdMode = AppConfig['enableProdMode'] ?? true;

        $proxyDir = DoctrineConfig['doctrine']['orm']['proxy_dir'] ??
            'var/cache/' . ($enableProdMode ? 'prod' : 'dev') . '/doctrine/orm/Proxies';

        $proxyNamespace = DoctrineConfig['doctrine']['orm']['proxy_namespace'] ?? 'Proxies';

        $config->setProxyDir($this->rootPath . $proxyDir . '/' . $domain);
        $config->setProxyNamespace($proxyNamespace);
    }

    /**
     * Register custom DBAL types
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function registerDbalTypes(): void
    {
        $dbalTypes = DoctrineConfig['doctrine']['dbal']['types'] ?? [];

        foreach ($dbalTypes as $typeName => $typeClass) {
            if (!Type::hasType($typeName)) {
                Type::addType($typeName, $typeClass);
            }
        }
    }

    /**
     * Wire metadata, query and result caches from doctrine.yaml.
     */
    private function configureCaches(Configuration $config): void
    {
        $config->setMetadataCache(DoctrineCacheFactory::create('metadata'));
        $config->setQueryCache(DoctrineCacheFactory::create('query'));
        $config->setResultCache(DoctrineCacheFactory::create('result'));
    }

    /**
     * Register custom DQL functions
     */
    private function registerCustomDqlFunctions(Configuration $config): void
    {
        $dqlConfig = DoctrineConfig['doctrine']['orm']['dql'] ?? [];

        if (empty($dqlConfig)) {
            return;
        }

        // Datetime functions
        foreach ($dqlConfig['datetime_functions'] ?? [] as $name => $class) {
            $config->addCustomDatetimeFunction($name, $class);
        }

        // Numeric functions
        foreach ($dqlConfig['numeric_functions'] ?? [] as $name => $class) {
            $config->addCustomNumericFunction($name, $class);
        }

        // String functions
        foreach ($dqlConfig['string_functions'] ?? [] as $name => $class) {
            $config->addCustomStringFunction($name, $class);
        }
    }

    /**
     * Create database connection from parameters
     *
     * @param array<string, mixed>|Connection $connectionParams
     */
    private function createConnection(
        array|Connection $connectionParams,
        ?Configuration $config = null
    ): Connection {
        if ($connectionParams instanceof Connection) {
            return $connectionParams;
        }

        return DriverManager::getConnection($connectionParams, $config ?? $this->config);
    }
}