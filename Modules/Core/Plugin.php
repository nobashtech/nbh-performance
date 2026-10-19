<?php
/** Plugin composition root. @package NBHPerformance */
namespace NBH\Performance\Modules\Core;

use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\CacheEngine\CacheLock;
use NBH\Performance\Modules\CacheEngine\CacheResponseMetadata;
use NBH\Performance\Modules\CacheEngine\CacheMaintenance;
use NBH\Performance\Modules\CacheEngine\CacheBootstrapManager;
use NBH\Performance\Modules\CacheEngine\DropInInstaller;
use NBH\Performance\Modules\CacheEngine\CacheVariantResolver;
use NBH\Performance\Modules\CacheEngine\CacheWriter;
use NBH\Performance\Modules\CacheEngine\PageCacheManager;
use NBH\Performance\Modules\CacheEngine\PreloadQueue;
use NBH\Performance\Modules\CacheEngine\PreloadRunStore;
use NBH\Performance\Modules\CacheEngine\PreloadController;
use NBH\Performance\Modules\CacheEngine\RequestEligibility;
use NBH\Performance\Modules\CacheEngine\RuntimeConfigManager;
use NBH\Performance\Modules\CacheEngine\SharedHostingAwareness;
use NBH\Performance\Modules\CacheEngine\SitemapDiscovery;
use NBH\Performance\Modules\Compatibility\CompatibilityManager;
use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Compatibility\CompatibilityPlatform;
use NBH\Performance\Modules\Compatibility\PageBuilders\EnterprisePageBuilderSupport;
use NBH\Performance\Modules\Compatibility\Forms\EnterpriseFormsSupport;
use NBH\Performance\Modules\Compatibility\Multilingual\MultilingualSupport;
use NBH\Performance\Modules\Compatibility\Hosting\HostingCompatibility;
use NBH\Performance\Modules\Compatibility\Consent\ConsentCompatibility;
use NBH\Performance\Modules\Compatibility\Cdn\CdnSecurityCompatibility;
use NBH\Performance\Modules\Compatibility\PluginDetector;
use NBH\Performance\Modules\Compatibility\OwnershipRegistry;
use NBH\Performance\Modules\Compatibility\ConflictDiagnosis;
use NBH\Performance\Modules\Compatibility\CompatibilityRemediationManager;
use NBH\Performance\Modules\Compatibility\SelectiveRollbackManager;
use NBH\Performance\Modules\CdnEdge\BunnyCdnClient;
use NBH\Performance\Modules\CdnEdge\CdnManager;
use NBH\Performance\Modules\CdnEdge\CdnUrlRewriter;
use NBH\Performance\Modules\CdnEdge\CloudflareApiClient;
use NBH\Performance\Modules\CdnEdge\CustomCdnClient;
use NBH\Performance\Modules\CdnEdge\EdgeExclusionRules;
use NBH\Performance\Modules\CdnEdge\KeyCdnClient;
use NBH\Performance\Modules\CdnEdge\ResourceHintInjector;
use NBH\Performance\Modules\CssJsOptimization\AssetCache;
use NBH\Performance\Modules\CssJsOptimization\AssetBundleCompiler;
use NBH\Performance\Modules\CssJsOptimization\AssetCompilationLock;
use NBH\Performance\Modules\CssJsOptimization\AssetManifestStore;
use NBH\Performance\Modules\CssJsOptimization\CssUrlRewriter;
use NBH\Performance\Modules\CssJsOptimization\AssetHealthMonitor;
use NBH\Performance\Modules\CssJsOptimization\AssetOptimizer;
use NBH\Performance\Modules\CssJsOptimization\AssetUrlResolver;
use NBH\Performance\Modules\CssJsOptimization\CssMinifier;
use NBH\Performance\Modules\CssJsOptimization\FileCombiner;
use NBH\Performance\Modules\CssJsOptimization\JsMinifier;
use NBH\Performance\Modules\CssJsOptimization\OptimizationController;
use NBH\Performance\Modules\CssJsOptimization\OptimizationExclusions;
use NBH\Performance\Modules\CssJsOptimization\ScriptLoadingManager;
use NBH\Performance\Modules\CssJsOptimization\ScriptCompatibilityRegistry;
use NBH\Performance\Modules\Hub\SystemStatus;
use NBH\Performance\Modules\Hub\FeatureToggleManager;
use NBH\Performance\Modules\Hub\AdvancedSettingsManager;
use NBH\Performance\Modules\Hub\SafeModeManager;
use NBH\Performance\Modules\Hub\ConflictReporter;
use NBH\Performance\Modules\Hub\DashboardController;
use NBH\Performance\Modules\Hub\HubPanelRegistrar;
use NBH\Performance\Modules\Hub\ProductQualityManager;
use NBH\Performance\Modules\Hub\SettingsRuntimeBridge;
use NBH\Performance\Modules\Hub\SettingsRegistry;
use NBH\Performance\Modules\DatabaseOptimization\BackupCoordinator;
use NBH\Performance\Modules\DatabaseOptimization\BackupStore;
use NBH\Performance\Modules\DatabaseOptimization\NativeSqlBackup;
use NBH\Performance\Modules\DatabaseOptimization\CleanupJournal;
use NBH\Performance\Modules\DatabaseOptimization\CleanupManager;
use NBH\Performance\Modules\DatabaseOptimization\CleanupScheduler;
use NBH\Performance\Modules\DatabaseOptimization\ConfirmationToken;
use NBH\Performance\Modules\DatabaseOptimization\DatabaseOptimizationController;
use NBH\Performance\Modules\DatabaseOptimization\DryRunPreview;
use NBH\Performance\Modules\DatabaseOptimization\TableOptimizer;
use NBH\Performance\Modules\ImageOptimization\ImageOptimizationManager;
use NBH\Performance\Modules\ImageOptimization\ImageOptimizationController;
use NBH\Performance\Modules\ImageOptimization\SafeProcessRunner;
use NBH\Performance\Modules\ImageOptimization\ImageProcessorRegistry;
use NBH\Performance\Modules\ImageOptimization\ImageVariantServer;
use NBH\Performance\Modules\ImageOptimization\LazyLoadManager;
use NBH\Performance\Modules\ImageOptimization\ResponsiveImageGenerator;
use NBH\Performance\Modules\ImageOptimization\WebpAvifConverter;
use NBH\Performance\Modules\ImageOptimization\ImageBackupManager;
use NBH\Performance\Modules\ImageOptimization\ImageCompressionManager;
use NBH\Performance\Modules\ImageOptimization\ImageOptimizationPipeline;
use NBH\Performance\Modules\ImageOptimization\HtmlMediaOptimizer;
use NBH\Performance\Modules\ImageOptimization\ImageOutputValidator;
use NBH\Performance\Modules\ImageOptimization\ImageOptimizationProfile;
use NBH\Performance\Modules\ImageOptimization\ImageBatchManager;
use NBH\Performance\Modules\ImageOptimization\LcpImageManager;
use NBH\Performance\Modules\ImageOptimization\MediaOffloadGuard;
use NBH\Performance\Modules\CriticalCss\CriticalCssContext;
use NBH\Performance\Modules\CriticalCss\CriticalCssStorage;
use NBH\Performance\Modules\CriticalCss\CriticalCssQueue;
use NBH\Performance\Modules\CriticalCss\RenderPathOptimizer;
use NBH\Performance\Modules\CriticalCss\CoverageValidator;
use NBH\Performance\Modules\CriticalCss\GenerationLock;
use NBH\Performance\Modules\CriticalCss\GeneratorRateLimiter;
use NBH\Performance\Modules\CriticalCss\HeadlessGeneratorClient;
use NBH\Performance\Modules\CriticalCss\SelectorSafelist;
use NBH\Performance\Modules\CriticalCss\UnusedCssRemover;
use NBH\Performance\Modules\CriticalCss\ParserBackedCssReducer;
use NBH\Performance\Modules\CriticalCss\UsedCssStorage;
use NBH\Performance\Modules\CriticalCss\VisualRegressionGuard;
use NBH\Performance\Modules\CriticalCss\BrowserEngineController;
use NBH\Performance\Modules\CriticalCss\BrowserJobStore;
use NBH\Performance\Modules\ThirdPartyScripts\ChatWidgetFacade;
use NBH\Performance\Modules\ThirdPartyScripts\ConsentManager;
use NBH\Performance\Modules\ThirdPartyScripts\ConsentScriptGuard;
use NBH\Performance\Modules\ThirdPartyScripts\MediaDeliveryController;
use NBH\Performance\Modules\ThirdPartyScripts\ThirdPartyOptimizationManager;
use NBH\Performance\Modules\ThirdPartyScripts\YouTubeFacade;
use NBH\Performance\Modules\ServerOptimization\CompressionDetector;
use NBH\Performance\Modules\ServerOptimization\HeartbeatController;
use NBH\Performance\Modules\ServerOptimization\HostingIntelligence;
use NBH\Performance\Modules\ServerOptimization\HostingEnvironmentDetector;
use NBH\Performance\Modules\ServerOptimization\AdaptiveRuntimePolicy;
use NBH\Performance\Modules\ServerOptimization\ProtocolDetector;
use NBH\Performance\Modules\ServerOptimization\ServerCapabilityReport;
use NBH\Performance\Modules\ServerOptimization\ServerGuidance;
use NBH\Performance\Modules\ServerOptimization\ServerOptimizationManager;
use NBH\Performance\Modules\ServerOptimization\ServerRuleManager;
use NBH\Performance\Modules\Rum\RumAggregator;
use NBH\Performance\Modules\Rum\RumBeacon;
use NBH\Performance\Modules\Rum\RumIngestionController;
use NBH\Performance\Modules\Rum\RumManager;
use NBH\Performance\Modules\Rum\RumRateLimiter;
use NBH\Performance\Modules\Rum\RumRetention;
use NBH\Performance\Modules\Rum\RumStorage;
use NBH\Performance\Modules\Rum\RumRollupStorage;
use NBH\Performance\Modules\Rum\RumRollupManager;
use NBH\Performance\Modules\Rum\RumToken;
use NBH\Performance\Modules\Licensing\AccountConnector;
use NBH\Performance\Modules\Licensing\LicenseController;
use NBH\Performance\Modules\Licensing\LicenseManager;
use NBH\Performance\Modules\Licensing\EntitlementController;
use NBH\Performance\Modules\Licensing\UpdateRollbackManager;
use NBH\Performance\Modules\Licensing\LicenseUpdatesController;
use NBH\Performance\Modules\Performance\WordPressBloatManager;
use NBH\Performance\Modules\Performance\ImageDimensionManager;
use NBH\Performance\Modules\Performance\InstantPageManager;
use NBH\Performance\Modules\Performance\ScriptManager;
use NBH\Performance\Modules\Performance\AssetRuleMatcher;
use NBH\Performance\Modules\Performance\AssetDependencyGraph;
use NBH\Performance\Modules\Performance\ScriptManagerAdminPage;
use NBH\Performance\Modules\Performance\ScriptManagerRuleStore;
use NBH\Performance\Modules\Performance\SpeculationRulesManager;
use NBH\Performance\Modules\Performance\ResourcePriorityManager;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use NBH\Performance\Modules\Performance\PriorityElementsToken;
use NBH\Performance\Modules\Performance\PriorityElementsController;
use NBH\Performance\Modules\Performance\PriorityElementsBeacon;
use NBH\Performance\Modules\Performance\ObjectCacheManager;
use NBH\Performance\Modules\Performance\AssetInventoryController;
use NBH\Performance\Modules\Performance\PerformanceBudgetManager;
use NBH\Performance\Modules\Diagnostics\DiagnosticsController;
use NBH\Performance\Modules\Diagnostics\ProductionReadinessScanner;
use NBH\Performance\Modules\Diagnostics\SupportReportExporter;
use NBH\Performance\Modules\Diagnostics\WpCliDiagnostics;
use NBH\Performance\Modules\Diagnostics\OperationalLogger;
use NBH\Performance\Modules\Diagnostics\OperationsHealthMonitor;
use NBH\Performance\Modules\Diagnostics\SupportBundleManager;
use NBH\Performance\Modules\Diagnostics\OperationsController;
use NBH\Performance\Modules\Diagnostics\ToolsSupportController;
use NBH\Performance\Modules\Diagnostics\RecoveryRunbook;
use NBH\Performance\Modules\Diagnostics\OperationalEventBridge;
use NBH\Performance\Modules\Intelligence\RecommendationEngine;
use NBH\Performance\Modules\Intelligence\ImpactTracker;
use NBH\Performance\Modules\Intelligence\IntelligenceController;
use NBH\Performance\Modules\Intelligence\AutoPerformanceController;
use NBH\Performance\Modules\Intelligence\SmartOptimizePlanBuilder;
use NBH\Performance\Modules\Intelligence\RumInsightEngine;
use NBH\Performance\Modules\Intelligence\PerformanceReportService;
use NBH\Performance\Modules\Intelligence\AutomaticRollbackManager;
use NBH\Performance\Modules\Intelligence\SmartOptimizeController;
use NBH\Performance\Modules\Release\DataRetentionPolicy;
use NBH\Performance\Modules\Release\ReleaseController;
use NBH\Performance\Modules\Release\SupplyChainController;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Jobs\JobWorker;
use NBH\Performance\Modules\Jobs\JobController;
use NBH\Performance\Modules\Jobs\WpCliJobs;
use NBH\Performance\Modules\Jobs\CacheInvalidationJobHandler;
use NBH\Performance\Modules\Jobs\WarmUrlJobHandler;
use NBH\Performance\Modules\Jobs\StagingValidationJobHandler;
use NBH\Performance\Modules\Jobs\CompatibilityScanJobHandler;
use NBH\Performance\Modules\Jobs\CriticalCssJobHandler;
use NBH\Performance\Modules\Jobs\ImageOptimizationJobHandler;
use NBH\Performance\Modules\Validation\StagingValidator;
use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\CacheEngine\InvalidationGraph;
use NBH\Performance\Modules\CacheEngine\PerPageControls;
use NBH\Performance\Modules\Compatibility\WooCommerceGuard;
use NBH\Performance\Modules\Compatibility\WooCommercePerformanceManager;
use NBH\Performance\Modules\Compatibility\CompatibilityTestMatrix;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestStorage;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestClient;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestRunner;
use NBH\Performance\Modules\PerformanceIntelligence\RegressionDetector;
use NBH\Performance\Modules\PerformanceIntelligence\UnifiedMetricsService;
use NBH\Performance\Modules\PerformanceIntelligence\PerformanceImpactTracker;
use NBH\Performance\Modules\PerformanceIntelligence\PerformanceIntelligenceController;
use NBH\Performance\Modules\PerformanceIntelligence\PerformanceBudgetEvaluator;
use NBH\Performance\Modules\PerformanceIntelligence\AutopilotManager;
use NBH\Performance\Modules\Jobs\LabTestJobHandler;
use NBH\Performance\Modules\Jobs\ImpactFinalizeJobHandler;
use NBH\Performance\Modules\Jobs\PostUpdateValidationJobHandler;
use NBH\Performance\Modules\Reliability\RuntimeReliabilityManager;
use NBH\Performance\Modules\Security\AuditLogger;
use NBH\Performance\Modules\Security\IntegrityVerifier;
use NBH\Performance\Modules\Security\EnterpriseSecurityManager;
use NBH\Performance\Modules\Enterprise\CacheTagManager;
use NBH\Performance\Modules\Enterprise\EdgeHtmlCacheManager;
use NBH\Performance\Modules\Enterprise\AiOperationsGateway;
use NBH\Performance\Modules\Enterprise\AgencyPortfolioManager;
use NBH\Performance\Modules\Enterprise\AgencyProfileManager;
use NBH\Performance\Modules\Enterprise\RedisTopologyManager;
final class Plugin {
	private $container;
	public function boot() {
		Lifecycle::maybe_upgrade();
		add_action(
			'init',
			static function () {
				// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled translations must also work before WordPress.org publication on WordPress 6.4; defer loading until init.
				load_plugin_textdomain( 'nbh-performance', false, dirname( NBH_PERFORMANCE_BASENAME ) . '/languages' );
			},
			0
		);
		$this->container = new Container();
		$this->register_services();
		$loader = new Loader();
		foreach ( array( 'cache_bootstrap', 'compatibility', 'compatibility_platform', 'page_builder_compatibility', 'forms_compatibility', 'multilingual_compatibility', 'hosting_compatibility', 'consent_compatibility', 'cdn_security_compatibility', 'selective_rollback', 'configuration_transactions', 'staging_validator', 'compatibility_matrix', 'post_update_validation_handler', 'job_worker', 'job_controller', 'wp_cli_jobs', 'invalidation_graph', 'per_page_controls', 'woocommerce_guard', 'woocommerce_performance', 'wordpress_bloat', 'image_dimensions', 'instant_page', 'script_manager', 'script_manager_admin', 'speculation_rules', 'priority_elements_controller', 'priority_elements_beacon', 'resource_priority', 'cache_tags', 'edge_html_cache', 'redis_topology', 'object_cache', 'agency_portfolio', 'agency_profiles', 'ai_operations_gateway', 'asset_inventory', 'performance_budgets', 'performance_impact_tracker', 'performance_intelligence_controller', 'autopilot_manager', 'runtime_reliability', 'runtime_config', 'page_cache', 'preload_queue', 'preload_controller', 'cache_maintenance', 'asset_optimizer', 'asset_bundle_compiler', 'script_loading', 'asset_health', 'php_css_generator', 'critical_css_queue', 'browser_job_store', 'browser_engine_controller', 'optimization_controller', 'render_path', 'image_optimization', 'lcp_image_manager', 'html_media_optimizer', 'image_optimization_controller', 'third_party_optimization', 'media_delivery_controller', 'database_controller', 'database_scheduler', 'cdn_manager', 'adaptive_runtime_policy', 'server_optimization', 'server_rule_manager', 'rum_manager', 'license_controller', 'entitlement_controller', 'update_rollback_manager', 'settings_registry', 'settings_runtime_bridge', 'dashboard_controller', 'hub_panel', 'diagnostics_controller', 'operations_controller', 'tools_support_controller', 'operational_event_bridge', 'wp_cli_diagnostics', 'intelligence_controller', 'auto_performance_controller', 'automatic_rollback', 'smart_optimize_controller', 'license_updates_controller', 'release_controller', 'supply_chain_controller', 'enterprise_security', 'compatibility_remediation', 'system_status' ) as $service ) {
			$loader->add( $this->container->get( $service ) );
		}
		$loader->register();
		do_action( 'nbh_performance_loaded', $this->container );
	}
	private function register_services() {
		$this->container->set(
			'integration_registry',
			static function () {
				return new IntegrationRegistry();
			}
		);
		$this->container->set(
			'compatibility_platform',
			static function ( Container $c ) {
				return new CompatibilityPlatform( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'page_builder_compatibility',
			static function ( Container $c ) {
				return new EnterprisePageBuilderSupport( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'forms_compatibility',
			static function ( Container $c ) {
				return new EnterpriseFormsSupport( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'multilingual_compatibility',
			static function ( Container $c ) {
				return new MultilingualSupport( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'hosting_compatibility',
			static function ( Container $c ) {
				return new HostingCompatibility( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'consent_compatibility',
			static function ( Container $c ) {
				return new ConsentCompatibility( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'cdn_security_compatibility',
			static function ( Container $c ) {
				return new CdnSecurityCompatibility( $c->get( 'integration_registry' ) );
			}
		);
		$this->container->set(
			'plugin_detector',
			static function () {
				return new PluginDetector();
			}
		);
		$this->container->set(
			'ownership_registry',
			static function ( Container $c ) {
				return new OwnershipRegistry( $c->get( 'plugin_detector' ) );
			}
		);
		$this->container->set(
			'compatibility_diagnosis',
			static function ( Container $c ) {
				return new ConflictDiagnosis( $c->get( 'plugin_detector' ), $c->get( 'ownership_registry' ) );
			}
		);
		$this->container->set(
			'dropin_installer',
			static function () {
				return new DropInInstaller();
			}
		);
		$this->container->set(
			'compatibility_remediation',
			static function ( Container $c ) {
				return new CompatibilityRemediationManager( $c->get( 'compatibility_diagnosis' ), $c->get( 'plugin_detector' ), $c->get( 'dropin_installer' ) );
			}
		);
		$this->container->set(
			'compatibility',
			static function ( Container $c ) {
				return new CompatibilityManager( $c->get( 'ownership_registry' ) );
			}
		);
		$this->container->set(
			'selective_rollback',
			static function ( Container $c ) {
				return new SelectiveRollbackManager( $c->get( 'compatibility_diagnosis' ) );
			}
		);
		$this->container->set(
			'job_store',
			static function () {
				return new JobStore();
			}
		);
		$this->container->set(
			'job_queue',
			static function ( Container $c ) {
				return new JobQueue( $c->get( 'job_store' ) );
			}
		);
		$this->container->set(
			'runtime_reliability',
			static function ( Container $c ) {
				return new RuntimeReliabilityManager( $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'configuration_transactions',
			static function ( Container $c ) {
				return new ConfigurationTransactionManager( $c->get( 'job_queue' ), $c->get( 'feature_toggles' ), $c->get( 'advanced_settings' ) );
			}
		);
		$this->container->set(
			'staging_validator',
			static function () {
				return new StagingValidator();
			}
		);
		$this->container->set(
			'compatibility_matrix',
			static function ( Container $c ) {
				return new CompatibilityTestMatrix( $c->get( 'compatibility_diagnosis' ), $c->get( 'job_queue' ) );
			}
		);

		$this->container->set(
			'wordpress_bloat',
			static function () {
				return new WordPressBloatManager();
			}
		);
		$this->container->set(
			'image_dimensions',
			static function () {
				return new ImageDimensionManager();
			}
		);
		$this->container->set(
			'instant_page',
			static function () {
				return new InstantPageManager();
			}
		);
		$this->container->set(
			'asset_rule_matcher',
			static function () {
				return new AssetRuleMatcher();
			}
		);
		$this->container->set(
			'asset_dependency_graph',
			static function () {
				return new AssetDependencyGraph();
			}
		);
		$this->container->set(
			'script_manager_rule_store',
			static function () {
				return new ScriptManagerRuleStore();
			}
		);
		$this->container->set(
			'script_manager',
			static function ( Container $c ) {
				return new ScriptManager( $c->get( 'asset_rule_matcher' ), $c->get( 'asset_dependency_graph' ), $c->get( 'script_manager_rule_store' ) );
			}
		);
		$this->container->set(
			'script_manager_admin',
			static function () {
				return new ScriptManagerAdminPage();
			}
		);
		$this->container->set(
			'speculation_rules',
			static function () {
				return new SpeculationRulesManager();
			}
		);
		$this->container->set(
			'priority_elements_storage',
			static function () {
				return new PriorityElementsStorage();
			}
		);
		$this->container->set(
			'priority_elements_token',
			static function () {
				return new PriorityElementsToken();
			}
		);
		$this->container->set(
			'priority_elements_controller',
			static function ( Container $c ) {
				return new PriorityElementsController( $c->get( 'priority_elements_storage' ), $c->get( 'priority_elements_token' ) );
			}
		);
		$this->container->set(
			'priority_elements_beacon',
			static function ( Container $c ) {
				return new PriorityElementsBeacon( $c->get( 'priority_elements_token' ), $c->get( 'priority_elements_storage' ) );
			}
		);
		$this->container->set(
			'resource_priority',
			static function ( Container $c ) {
				return new ResourcePriorityManager( $c->get( 'priority_elements_storage' ) );
			}
		);
		$this->container->set(
			'object_cache',
			static function () {
				return new ObjectCacheManager();
			}
		);
		$this->container->set(
			'asset_inventory',
			static function ( Container $c ) {
				return new AssetInventoryController( $c->get( 'asset_dependency_graph' ) );
			}
		);
		$this->container->set(
			'performance_budgets',
			static function () {
				return new PerformanceBudgetManager();
			}
		);
		$this->container->set(
			'lab_test_storage',
			static function () {
				return new LabTestStorage();
			}
		);
		$this->container->set(
			'lab_test_client',
			static function () {
				return new LabTestClient();
			}
		);
		$this->container->set(
			'regression_detector',
			static function ( Container $c ) {
				return new RegressionDetector( $c->get( 'lab_test_storage' ) );
			}
		);
		$this->container->set(
			'rum_rollup_storage',
			static function () {
				return new RumRollupStorage();
			}
		);
		$this->container->set(
			'performance_budget_evaluator',
			static function () {
				return new PerformanceBudgetEvaluator();
			}
		);
		$this->container->set(
			'unified_metrics',
			static function ( Container $c ) {
				return new UnifiedMetricsService( $c->get( 'lab_test_storage' ), $c->get( 'regression_detector' ), $c->get( 'rum_rollup_storage' ), $c->get( 'performance_budget_evaluator' ) );
			}
		);
		$this->container->set(
			'lab_test_runner',
			static function ( Container $c ) {
				return new LabTestRunner( $c->get( 'lab_test_client' ), $c->get( 'lab_test_storage' ), $c->get( 'regression_detector' ) );
			}
		);
		$this->container->set(
			'lab_test_job_handler',
			static function ( Container $c ) {
				return new LabTestJobHandler( $c->get( 'lab_test_runner' ) );
			}
		);
		$this->container->set(
			'impact_finalize_job_handler',
			static function ( Container $c ) {
				return new ImpactFinalizeJobHandler( $c->get( 'performance_impact_tracker' ) );
			}
		);
		$this->container->set(
			'performance_impact_tracker',
			static function ( Container $c ) {
				return new PerformanceImpactTracker( $c->get( 'unified_metrics' ), $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'performance_intelligence_controller',
			static function ( Container $c ) {
				return new PerformanceIntelligenceController( $c->get( 'lab_test_storage' ), $c->get( 'unified_metrics' ), $c->get( 'regression_detector' ), $c->get( 'job_queue' ), $c->get( 'performance_impact_tracker' ) );
			}
		);
		$this->container->set(
			'autopilot_manager',
			static function ( Container $c ) {
				return new AutopilotManager( $c->get( 'unified_metrics' ), $c->get( 'performance_budget_evaluator' ), $c->get( 'configuration_transactions' ), $c->get( 'staging_validator' ) );
			}
		);
		$this->container->set(
			'cache_tags',
			static function ( Container $c ) {
				return new CacheTagManager( $c->get( 'job_queue' ), $c->get( 'security_audit' ) );
			}
		);
		$this->container->set(
			'edge_html_cache',
			static function ( Container $c ) {
				return new EdgeHtmlCacheManager( $c->get( 'request_eligibility' ), $c->get( 'cache_tags' ), $c->get( 'security_audit' ) );
			}
		);
		$this->container->set(
			'redis_topology',
			static function () {
				return new RedisTopologyManager();
			}
		);
		$this->container->set(
			'agency_portfolio',
			static function ( Container $c ) {
				return new AgencyPortfolioManager( $c->get( 'security_audit' ), $c->get( 'license_manager' ) );
			}
		);
		$this->container->set(
			'agency_profiles',
			static function ( Container $c ) {
				return new AgencyProfileManager( $c->get( 'feature_toggles' ), $c->get( 'advanced_settings' ), $c->get( 'configuration_transactions' ), $c->get( 'security_audit' ), $c->get( 'license_manager' ) );
			}
		);
		$this->container->set(
			'ai_operations_gateway',
			static function ( Container $c ) {
				return new AiOperationsGateway( $c->get( 'page_cache' ), $c->get( 'safe_mode_manager' ), $c->get( 'job_store' ), $c->get( 'unified_metrics' ), $c->get( 'security_audit' ) );
			}
		);

		$this->container->set(
			'cache_bootstrap',
			static function () {
				return new CacheBootstrapManager();
			}
		);
		$this->container->set(
			'request_eligibility',
			static function () {
				return new RequestEligibility();
			}
		);
		$this->container->set(
			'cache_writer',
			static function () {
				return new CacheWriter();
			}
		);
		$this->container->set(
			'cache_index',
			static function () {
				return new CacheIndex();
			}
		);
		$this->container->set(
			'cache_variants',
			static function () {
				return new CacheVariantResolver();
			}
		);
		$this->container->set(
			'cache_lock',
			static function () {
				return new CacheLock();
			}
		);
		$this->container->set(
			'cache_response_metadata',
			static function () {
				return new CacheResponseMetadata();
			}
		);
		$this->container->set(
			'shared_hosting',
			static function () {
				return new SharedHostingAwareness();
			}
		);
		$this->container->set(
			'sitemaps',
			static function () {
				return new SitemapDiscovery();
			}
		);
		$this->container->set(
			'preload_runs',
			static function () {
				return new PreloadRunStore();
			}
		);
		$this->container->set(
			'preload_queue',
			static function ( Container $c ) {
				return new PreloadQueue( $c->get( 'shared_hosting' ), $c->get( 'sitemaps' ), $c->get( 'job_queue' ), $c->get( 'preload_runs' ) );
			}
		);
		$this->container->set(
			'preload_controller',
			static function ( Container $c ) {
				return new PreloadController( $c->get( 'preload_queue' ), $c->get( 'sitemaps' ), $c->get( 'preload_runs' ), $c->get( 'priority_elements_storage' ) );
			}
		);
		$this->container->set(
			'runtime_config',
			static function () {
				return new RuntimeConfigManager();
			}
		);
		$this->container->set(
			'cache_maintenance',
			static function ( Container $c ) {
				return new CacheMaintenance( $c->get( 'cache_index' ) );
			}
		);
		$this->container->set(
			'page_cache',
			static function ( Container $c ) {
				return new PageCacheManager( $c->get( 'compatibility' ), $c->get( 'request_eligibility' ), $c->get( 'cache_writer' ), $c->get( 'cache_index' ), $c->get( 'cache_variants' ), $c->get( 'preload_queue' ), $c->get( 'cache_lock' ), $c->get( 'cache_response_metadata' ) );
			}
		);
		$this->container->set(
			'cache_invalidation_handler',
			static function ( Container $c ) {
				return new CacheInvalidationJobHandler( $c->get( 'cache_index' ), $c->get( 'page_cache' ), $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'warm_url_handler',
			static function ( Container $c ) {
				return new WarmUrlJobHandler( $c->get( 'preload_runs' ) );
			}
		);
		$this->container->set(
			'staging_validation_handler',
			static function ( Container $c ) {
				return new StagingValidationJobHandler( $c->get( 'staging_validator' ), $c->get( 'configuration_transactions' ) );
			}
		);
		$this->container->set(
			'compatibility_scan_handler',
			static function ( Container $c ) {
				return new CompatibilityScanJobHandler( $c->get( 'compatibility_matrix' ) );
			}
		);
		$this->container->set(
			'post_update_validation_handler',
			static function ( Container $c ) {
				return new PostUpdateValidationJobHandler( $c->get( 'staging_validator' ), $c->get( 'runtime_reliability' ), $c->get( 'update_rollback_manager' ) );
			}
		);
		$this->container->set(
			'job_worker',
			static function ( Container $c ) {
				return new JobWorker( $c->get( 'job_store' ), array( $c->get( 'cache_invalidation_handler' ), $c->get( 'warm_url_handler' ), $c->get( 'staging_validation_handler' ), $c->get( 'compatibility_scan_handler' ), $c->get( 'post_update_validation_handler' ), $c->get( 'critical_css_job_handler' ), $c->get( 'image_optimization_job_handler' ), $c->get( 'lab_test_job_handler' ), $c->get( 'impact_finalize_job_handler' ) ) );
			}
		);
		$this->container->set(
			'job_controller',
			static function ( Container $c ) {
				return new JobController( $c->get( 'job_store' ), $c->get( 'job_worker' ) );
			}
		);
		$this->container->set(
			'wp_cli_jobs',
			static function ( Container $c ) {
				return new WpCliJobs( $c->get( 'job_store' ), $c->get( 'job_worker' ) );
			}
		);
		$this->container->set(
			'invalidation_graph',
			static function ( Container $c ) {
				return new InvalidationGraph( $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'per_page_controls',
			static function ( Container $c ) {
				return new PerPageControls( $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'woocommerce_guard',
			static function ( Container $c ) {
				return new WooCommerceGuard( $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'woocommerce_performance',
			static function ( Container $c ) {
				return new WooCommercePerformanceManager( $c->get( 'job_queue' ) );
			}
		);

		$this->container->set(
			'asset_resolver',
			static function () {
				return new AssetUrlResolver();
			}
		);
		$this->container->set(
			'css_minifier',
			static function () {
				return new CssMinifier();
			}
		);
		$this->container->set(
			'js_minifier',
			static function () {
				return new JsMinifier();
			}
		);
		$this->container->set(
			'asset_cache',
			static function () {
				return new AssetCache();
			}
		);
		$this->container->set(
			'asset_exclusions',
			static function () {
				return new OptimizationExclusions();
			}
		);
		$this->container->set(
			'file_combiner',
			static function () {
				return new FileCombiner();
			}
		);
		$this->container->set(
			'script_compatibility',
			static function () {
				return new ScriptCompatibilityRegistry();
			}
		);
		$this->container->set(
			'script_loading',
			static function ( Container $c ) {
				return new ScriptLoadingManager( $c->get( 'script_compatibility' ) );
			}
		);
		$this->container->set(
			'asset_health',
			static function () {
				return new AssetHealthMonitor();
			}
		);
		$this->container->set(
			'asset_optimizer',
			static function ( Container $c ) {
				return new AssetOptimizer( $c->get( 'asset_resolver' ), $c->get( 'css_minifier' ), $c->get( 'js_minifier' ), $c->get( 'asset_cache' ), $c->get( 'asset_exclusions' ), $c->get( 'file_combiner' ) );
			}
		);
		$this->container->set(
			'asset_manifest_store',
			static function () {
				return new AssetManifestStore();
			}
		);
		$this->container->set(
			'asset_compilation_lock',
			static function () {
				return new AssetCompilationLock();
			}
		);
		$this->container->set(
			'css_url_rewriter',
			static function ( Container $c ) {
				return new CssUrlRewriter( $c->get( 'asset_resolver' ) );
			}
		);
		$this->container->set(
			'asset_bundle_compiler',
			static function ( Container $c ) {
				return new AssetBundleCompiler( $c->get( 'asset_resolver' ), $c->get( 'asset_cache' ), $c->get( 'css_minifier' ), $c->get( 'js_minifier' ), $c->get( 'css_url_rewriter' ), $c->get( 'asset_exclusions' ), $c->get( 'file_combiner' ), $c->get( 'asset_manifest_store' ), $c->get( 'asset_compilation_lock' ) );
			}
		);

		$this->container->set(
			'critical_css_context',
			static function () {
				return new CriticalCssContext();
			}
		);
		$this->container->set(
			'critical_css_storage',
			static function () {
				return new CriticalCssStorage();
			}
		);
		$this->container->set(
			'used_css_storage',
			static function () {
				return new UsedCssStorage();
			}
		);
		$this->container->set(
			'generator_rate_limiter',
			static function () {
				return new GeneratorRateLimiter();
			}
		);
		$this->container->set(
			'generation_lock',
			static function () {
				return new GenerationLock();
			}
		);
		$this->container->set(
			'selector_safelist',
			static function () {
				return new SelectorSafelist();
			}
		);
		$this->container->set(
			'visual_regression_guard',
			static function () {
				return new VisualRegressionGuard();
			}
		);
		$this->container->set(
			'coverage_validator',
			static function ( Container $c ) {
				return new CoverageValidator( $c->get( 'visual_regression_guard' ) );
			}
		);
		$this->container->set(
			'parser_css_reducer',
			static function ( Container $c ) {
				return new ParserBackedCssReducer( $c->get( 'selector_safelist' ) );
			}
		);
		$this->container->set(
			'unused_css_remover',
			static function ( Container $c ) {
				return new UnusedCssRemover( $c->get( 'selector_safelist' ), $c->get( 'coverage_validator' ), $c->get( 'parser_css_reducer' ), $c->get( 'visual_regression_guard' ) );
			}
		);
		$this->container->set(
			'browser_job_store',
			static function () {
				return new BrowserJobStore();
			}
		);
		$this->container->set(
			'headless_generator',
			static function ( Container $c ) {
				return new HeadlessGeneratorClient( $c->get( 'generator_rate_limiter' ), $c->get( 'selector_safelist' ), $c->get( 'browser_job_store' ) );
			}
		);
		$this->container->set(
			'php_css_generator',
			static function () {
				return new \NBH\Performance\Modules\CriticalCss\PhpCssGenerator();
			}
		);
		$this->container->set(
			'critical_css_queue',
			static function ( Container $c ) {
				return new CriticalCssQueue( $c->get( 'critical_css_context' ), $c->get( 'headless_generator' ), $c->get( 'critical_css_storage' ), $c->get( 'used_css_storage' ), $c->get( 'generation_lock' ), $c->get( 'unused_css_remover' ), $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'critical_css_job_handler',
			static function ( Container $c ) {
				return new CriticalCssJobHandler( $c->get( 'critical_css_queue' ) );
			}
		);
		$this->container->set(
			'browser_engine_controller',
			static function ( Container $c ) {
				return new BrowserEngineController( $c->get( 'critical_css_queue' ), $c->get( 'priority_elements_storage' ), $c->get( 'browser_job_store' ) );
			}
		);
		$this->container->set(
			'optimization_controller',
			static function ( Container $c ) {
				return new OptimizationController( $c->get( 'asset_optimizer' ), $c->get( 'asset_bundle_compiler' ), $c->get( 'asset_health' ), $c->get( 'critical_css_storage' ), $c->get( 'used_css_storage' ), $c->get( 'critical_css_queue' ), $c->get( 'browser_job_store' ) );
			}
		);
		$this->container->set(
			'render_path',
			static function ( Container $c ) {
				return new RenderPathOptimizer( $c->get( 'critical_css_storage' ), $c->get( 'used_css_storage' ), $c->get( 'critical_css_context' ) );
			}
		);
		$this->container->set(
			'image_process_runner',
			static function () {
				return new SafeProcessRunner();
			}
		);
		$this->container->set(
			'image_processors',
			static function ( Container $c ) {
				return new ImageProcessorRegistry( $c->get( 'image_process_runner' ) );
			}
		);
		$this->container->set(
			'image_output_validator',
			static function () {
				return new ImageOutputValidator();
			}
		);
		$this->container->set(
			'image_profile',
			static function () {
				return new ImageOptimizationProfile();
			}
		);
		$this->container->set(
			'image_offload_guard',
			static function () {
				return new MediaOffloadGuard();
			}
		);
		$this->container->set(
			'image_batch_manager',
			static function ( Container $c ) {
				return new ImageBatchManager( $c->get( 'job_queue' ) );
			}
		);
		$this->container->set(
			'image_converter',
			static function ( Container $c ) {
				return new WebpAvifConverter( $c->get( 'image_processors' ), $c->get( 'image_output_validator' ), $c->get( 'image_profile' ) );
			}
		);
		$this->container->set(
			'image_backup',
			static function () {
				return new ImageBackupManager();
			}
		);
		$this->container->set(
			'image_compression',
			static function ( Container $c ) {
				return new ImageCompressionManager( $c->get( 'image_output_validator' ), $c->get( 'image_profile' ) );
			}
		);
		$this->container->set(
			'image_pipeline',
			static function ( Container $c ) {
				return new ImageOptimizationPipeline( $c->get( 'image_converter' ), $c->get( 'image_backup' ), $c->get( 'image_compression' ) );
			}
		);
		$this->container->set(
			'image_optimization_job_handler',
			static function ( Container $c ) {
				return new ImageOptimizationJobHandler( $c->get( 'image_pipeline' ), $c->get( 'image_batch_manager' ) );
			}
		);
		$this->container->set(
			'image_variant_server',
			static function () {
				return new ImageVariantServer();
			}
		);
		$this->container->set(
			'lazy_load',
			static function () {
				return new LazyLoadManager();
			}
		);
		$this->container->set(
			'responsive_images',
			static function () {
				return new ResponsiveImageGenerator();
			}
		);
		$this->container->set(
			'image_optimization',
			static function ( Container $c ) {
				return new ImageOptimizationManager( $c->get( 'image_pipeline' ), $c->get( 'image_variant_server' ), $c->get( 'lazy_load' ), $c->get( 'responsive_images' ) );
			}
		);
		$this->container->set(
			'lcp_image_manager',
			static function () {
				return new LcpImageManager();
			}
		);
		$this->container->set(
			'html_media_optimizer',
			static function () {
				return new HtmlMediaOptimizer();
			}
		);
		$this->container->set(
			'image_optimization_controller',
			static function ( Container $c ) {
				return new ImageOptimizationController( $c->get( 'image_pipeline' ), $c->get( 'image_processors' ), $c->get( 'job_queue' ), $c->get( 'image_batch_manager' ), $c->get( 'image_profile' ), $c->get( 'image_offload_guard' ) );
			}
		);
		$this->container->set(
			'third_party_consent',
			static function () {
				return new ConsentManager();
			}
		);
		$this->container->set(
			'consent_script_guard',
			static function ( Container $c ) {
				return new ConsentScriptGuard( $c->get( 'third_party_consent' ) );
			}
		);
		$this->container->set(
			'youtube_facade',
			static function ( Container $c ) {
				return new YouTubeFacade( $c->get( 'third_party_consent' ) );
			}
		);
		$this->container->set(
			'chat_facade',
			static function ( Container $c ) {
				return new ChatWidgetFacade( $c->get( 'third_party_consent' ) );
			}
		);
		$this->container->set(
			'third_party_optimization',
			static function ( Container $c ) {
				return new ThirdPartyOptimizationManager( $c->get( 'consent_script_guard' ), $c->get( 'youtube_facade' ), $c->get( 'chat_facade' ) );
			}
		);
		$this->container->set(
			'media_delivery_controller',
			static function () {
				return new MediaDeliveryController();
			}
		);
		$this->container->set(
			'database_preview',
			static function () {
				return new DryRunPreview();
			}
		);
		$this->container->set(
			'database_tokens',
			static function () {
				return new ConfirmationToken();
			}
		);
		$this->container->set(
			'database_backup_store',
			static function () {
				return new BackupStore();
			}
		);
		$this->container->set(
			'database_native_backup',
			static function () {
				return new NativeSqlBackup();
			}
		);
		$this->container->set(
			'database_backup',
			static function ( Container $c ) {
				return new BackupCoordinator( $c->get( 'database_backup_store' ), $c->get( 'database_native_backup' ) );
			}
		);
		$this->container->set(
			'database_cleanup',
			static function () {
				return new CleanupManager();
			}
		);
		$this->container->set(
			'table_optimizer',
			static function () {
				return new TableOptimizer();
			}
		);
		$this->container->set(
			'database_journal',
			static function () {
				return new CleanupJournal();
			}
		);
		$this->container->set(
			'database_controller',
			static function ( Container $c ) {
				return new DatabaseOptimizationController( $c->get( 'database_preview' ), $c->get( 'database_tokens' ), $c->get( 'database_backup' ), $c->get( 'database_cleanup' ), $c->get( 'table_optimizer' ), $c->get( 'database_journal' ) );
			}
		);
		$this->container->set(
			'database_scheduler',
			static function ( Container $c ) {
				return new CleanupScheduler( $c->get( 'database_cleanup' ), $c->get( 'database_preview' ), $c->get( 'database_backup' ), $c->get( 'database_journal' ) );
			}
		);
		$this->container->set(
			'cdn_rewriter',
			static function () {
				return new CdnUrlRewriter();
			}
		);
		$this->container->set(
			'resource_hints',
			static function () {
				return new ResourceHintInjector();
			}
		);
		$this->container->set(
			'edge_exclusions',
			static function () {
				return new EdgeExclusionRules();
			}
		);
		$this->container->set(
			'cloudflare',
			static function () {
				return new CloudflareApiClient();
			}
		);
		$this->container->set(
			'bunnycdn',
			static function () {
				return new BunnyCdnClient();
			}
		);
		$this->container->set(
			'keycdn',
			static function () {
				return new KeyCdnClient();
			}
		);
		$this->container->set(
			'custom_cdn',
			static function () {
				return new CustomCdnClient();
			}
		);
		$this->container->set(
			'cdn_manager',
			static function ( Container $c ) {
				return new CdnManager(
					array(
						'cloudflare' => $c->get( 'cloudflare' ),
						'bunnycdn'   => $c->get( 'bunnycdn' ),
						'keycdn'     => $c->get( 'keycdn' ),
						'custom'     => $c->get( 'custom_cdn' ),
					),
					$c->get( 'cdn_rewriter' ),
					$c->get( 'resource_hints' ),
					$c->get( 'edge_exclusions' )
				);
			}
		);
		$this->container->set(
			'compression_detector',
			static function () {
				return new CompressionDetector();
			}
		);
		$this->container->set(
			'protocol_detector',
			static function () {
				return new ProtocolDetector();
			}
		);
		$this->container->set(
			'hosting_intelligence',
			static function () {
				return new HostingIntelligence();
			}
		);
		$this->container->set(
			'hosting_environment',
			static function () {
				return new HostingEnvironmentDetector();
			}
		);
		$this->container->set(
			'adaptive_runtime_policy',
			static function ( Container $c ) {
				return new AdaptiveRuntimePolicy( $c->get( 'hosting_environment' ) );
			}
		);
		$this->container->set(
			'server_guidance',
			static function () {
				return new ServerGuidance();
			}
		);
		$this->container->set(
			'heartbeat_controller',
			static function () {
				return new HeartbeatController();
			}
		);
		$this->container->set(
			'server_report',
			static function ( Container $c ) {
				return new ServerCapabilityReport( $c->get( 'compression_detector' ), $c->get( 'protocol_detector' ), $c->get( 'hosting_intelligence' ), $c->get( 'server_guidance' ), $c->get( 'hosting_environment' ) );
			}
		);
		$this->container->set(
			'server_optimization',
			static function ( Container $c ) {
				return new ServerOptimizationManager( $c->get( 'heartbeat_controller' ), $c->get( 'server_report' ) );
			}
		);
		$this->container->set(
			'server_rule_manager',
			static function ( Container $c ) {
				return new ServerRuleManager( $c->get( 'hosting_environment' ) );
			}
		);
		$this->container->set(
			'rum_storage',
			static function () {
				return new RumStorage();
			}
		);
		$this->container->set(
			'rum_token',
			static function () {
				return new RumToken();
			}
		);
		$this->container->set(
			'rum_rate_limiter',
			static function () {
				return new RumRateLimiter();
			}
		);
		$this->container->set(
			'rum_beacon',
			static function ( Container $c ) {
				return new RumBeacon( $c->get( 'rum_token' ) );
			}
		);
		$this->container->set(
			'rum_ingestion',
			static function ( Container $c ) {
				return new RumIngestionController( $c->get( 'rum_storage' ), $c->get( 'rum_rate_limiter' ), $c->get( 'rum_token' ) );
			}
		);
		$this->container->set(
			'rum_aggregator',
			static function () {
				return new RumAggregator();
			}
		);
		$this->container->set(
			'rum_retention',
			static function ( Container $c ) {
				return new RumRetention( $c->get( 'rum_storage' ) );
			}
		);
		$this->container->set(
			'rum_rollup_manager',
			static function ( Container $c ) {
				return new RumRollupManager( $c->get( 'rum_rollup_storage' ) );
			}
		);
		$this->container->set(
			'rum_manager',
			static function ( Container $c ) {
				return new RumManager( $c->get( 'rum_beacon' ), $c->get( 'rum_ingestion' ), $c->get( 'rum_aggregator' ), $c->get( 'rum_retention' ), $c->get( 'rum_rollup_manager' ) );
			}
		);
		$this->container->set(
			'license_manager',
			static function () {
				return new LicenseManager();
			}
		);
		$this->container->set(
			'license_controller',
			static function ( Container $c ) {
				return new LicenseController( $c->get( 'license_manager' ) );
			}
		);
		$this->container->set(
			'entitlement_controller',
			static function ( Container $c ) {
				return new EntitlementController( $c->get( 'license_manager' ) );
			}
		);
		$this->container->set(
			'update_rollback_manager',
			static function () {
				return new UpdateRollbackManager();
			}
		);
		$this->container->set(
			'account_connector',
			static function () {
				return new AccountConnector();
			}
		);
		$this->container->set(
			'feature_toggles',
			static function () {
				return new FeatureToggleManager();
			}
		);
		$this->container->set(
			'advanced_settings',
			static function () {
				return new AdvancedSettingsManager();
			}
		);
		$this->container->set(
			'safe_mode_manager',
			static function ( Container $c ) {
				return new SafeModeManager( $c->get( 'feature_toggles' ) );
			}
		);
		$this->container->set(
			'conflict_reporter',
			static function ( Container $c ) {
				return new ConflictReporter( $c->get( 'compatibility_diagnosis' ) );
			}
		);
		$this->container->set(
			'settings_registry',
			static function () {
				return new SettingsRegistry();
			}
		);
		$this->container->set(
			'settings_runtime_bridge',
			static function () {
				return new SettingsRuntimeBridge();
			}
		);
		$this->container->set(
			'product_quality',
			static function ( Container $c ) {
				return new ProductQualityManager( $c->get( 'page_cache' ), $c->get( 'safe_mode_manager' ), $c->get( 'ownership_registry' ) );
			}
		);
		$this->container->set(
			'dashboard_controller',
			static function ( Container $c ) {
				return new DashboardController( $c->get( 'feature_toggles' ), $c->get( 'advanced_settings' ), $c->get( 'safe_mode_manager' ), $c->get( 'conflict_reporter' ), $c->get( 'license_manager' ), $c->get( 'compatibility_remediation' ), $c->get( 'product_quality' ) );
			}
		);
		$this->container->set(
			'hub_panel',
			static function ( Container $c ) {
				return new HubPanelRegistrar( $c->get( 'page_cache' ) );
			}
		);
		$this->container->set(
			'readiness_scanner',
			static function () {
				return new ProductionReadinessScanner();
			}
		);
		$this->container->set(
			'operational_logger',
			static function () {
				return new OperationalLogger();
			}
		);
		$this->container->set(
			'operations_health',
			static function () {
				return new OperationsHealthMonitor();
			}
		);
		$this->container->set(
			'recovery_runbook',
			static function () {
				return new RecoveryRunbook();
			}
		);
		$this->container->set(
			'support_report_exporter',
			static function ( Container $c ) {
				return new SupportReportExporter( $c->get( 'readiness_scanner' ), $c->get( 'operations_health' ), $c->get( 'operational_logger' ), $c->get( 'recovery_runbook' ) );
			}
		);
		$this->container->set(
			'support_bundle_manager',
			static function ( Container $c ) {
				return new SupportBundleManager( $c->get( 'support_report_exporter' ), $c->get( 'operational_logger' ), $c->get( 'operations_health' ) );
			}
		);
		$this->container->set(
			'diagnostics_controller',
			static function ( Container $c ) {
				return new DiagnosticsController( $c->get( 'readiness_scanner' ), $c->get( 'support_report_exporter' ) );
			}
		);
		$this->container->set(
			'operations_controller',
			static function ( Container $c ) {
				return new OperationsController( $c->get( 'operations_health' ), $c->get( 'operational_logger' ), $c->get( 'support_bundle_manager' ) );
			}
		);
		$this->container->set(
			'tools_support_controller',
			static function ( Container $c ) {
				return new ToolsSupportController( $c->get( 'operations_health' ), $c->get( 'operational_logger' ), $c->get( 'product_quality' ), $c->get( 'job_store' ) );
			}
		);
		$this->container->set(
			'operational_event_bridge',
			static function ( Container $c ) {
				return new OperationalEventBridge( $c->get( 'operational_logger' ) );
			}
		);
		$this->container->set(
			'wp_cli_diagnostics',
			static function ( Container $c ) {
				return new WpCliDiagnostics( $c->get( 'readiness_scanner' ), $c->get( 'support_report_exporter' ), $c->get( 'operations_health' ), $c->get( 'operational_logger' ), $c->get( 'support_bundle_manager' ) );
			}
		);
		$this->container->set(
			'recommendation_engine',
			static function ( Container $c ) {
				return new RecommendationEngine( $c->get( 'server_report' ), $c->get( 'compatibility_diagnosis' ) );
			}
		);
		$this->container->set(
			'impact_tracker',
			static function () {
				return new ImpactTracker();
			}
		);
		$this->container->set(
			'intelligence_controller',
			static function ( Container $c ) {
				return new IntelligenceController( $c->get( 'recommendation_engine' ), $c->get( 'impact_tracker' ), $c->get( 'selective_rollback' ) );
			}
		);
		$this->container->set(
			'auto_performance_controller',
			static function ( Container $c ) {
				return new AutoPerformanceController( $c->get( 'feature_toggles' ), $c->get( 'advanced_settings' ), $c->get( 'safe_mode_manager' ) );
			}
		);
		$this->container->set(
			'rum_insight_engine',
			static function ( Container $c ) {
				return new RumInsightEngine( $c->get( 'unified_metrics' ) );
			}
		);
		$this->container->set(
			'smart_optimize_plan',
			static function ( Container $c ) {
				return new SmartOptimizePlanBuilder( $c->get( 'server_report' ), $c->get( 'compatibility_diagnosis' ), $c->get( 'unified_metrics' ), $c->get( 'recommendation_engine' ), $c->get( 'rum_insight_engine' ) );
			}
		);
		$this->container->set(
			'performance_report',
			static function ( Container $c ) {
				return new PerformanceReportService( $c->get( 'unified_metrics' ), $c->get( 'rum_insight_engine' ) );
			}
		);
		$this->container->set(
			'automatic_rollback',
			static function ( Container $c ) {
				return new AutomaticRollbackManager( $c->get( 'configuration_transactions' ), $c->get( 'staging_validator' ), $c->get( 'security_audit' ) );
			}
		);
		$this->container->set(
			'smart_optimize_controller',
			static function ( Container $c ) {
				return new SmartOptimizeController( $c->get( 'smart_optimize_plan' ), $c->get( 'feature_toggles' ), $c->get( 'advanced_settings' ), $c->get( 'configuration_transactions' ), $c->get( 'safe_mode_manager' ), $c->get( 'job_queue' ), $c->get( 'performance_report' ), $c->get( 'automatic_rollback' ) );
			}
		);
		$this->container->set(
			'data_retention_policy',
			static function () {
				return new DataRetentionPolicy();
			}
		);
		$this->container->set(
			'release_controller',
			static function ( Container $c ) {
				return new ReleaseController( $c->get( 'data_retention_policy' ) );
			}
		);
		$this->container->set(
			'supply_chain_controller',
			static function () {
				return new SupplyChainController();
			}
		);
		$this->container->set(
			'license_updates_controller',
			static function ( Container $c ) {
				return new LicenseUpdatesController( $c->get( 'license_manager' ), $c->get( 'account_connector' ), $c->get( 'update_rollback_manager' ), $c->get( 'supply_chain_controller' ) );
			}
		);
		$this->container->set(
			'security_audit',
			static function () {
				return new AuditLogger();
			}
		);
		$this->container->set(
			'integrity_verifier',
			static function () {
				return new IntegrityVerifier();
			}
		);
		$this->container->set(
			'enterprise_security',
			static function ( Container $c ) {
				return new EnterpriseSecurityManager( $c->get( 'security_audit' ) );
			}
		);
		$this->container->set(
			'system_status',
			static function ( Container $c ) {
				return new SystemStatus( $c->get( 'compatibility_remediation' ), $c->get( 'dropin_installer' ) );
			}
		);
	}
}
