<?php
# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// $wgReadOnly = ( PHP_SAPI === 'cli' ) ? false : 'This wiki is currently being upgraded to a newer software version. Please check back in a couple of hours.';

$wgReadOnly = getenv( 'MW_READ_ONLY' ) ?: false; 

$wgSitename = "Coasterpedia";
$wgMetaNamespace = "Coasterpedia";

$wgShowExceptionDetails = true;

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "/w";
$wgArticlePath = "/wiki/$1";
$wgUsePathInfo = true;

## The protocol and server name to use in fully-qualified URLs
$wgServer   = getenv( 'MW_SERVER' ) ?: "https://coasterpedia.net";
$wgCanonicalServer = "https://coasterpedia.net";

$wgEnableCanonicalServerLink = true;
$wgSecureLogin = true;

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

## Uncomment this to disable output compression
### Causes issues behind Caches
$wgDisableOutputCompression = true;

## The URL paths to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
$wgLogos = [
	'1x' => "https://images.coasterpedia.net/thumb/0/09/Cp_pride.png/32px-Cp_pride.png.webp",
	'1.5x' => "https://images.coasterpedia.net/thumb/0/09/Cp_pride.png/48px-Cp_pride.png.webp",
	'2x' => "https://images.coasterpedia.net/thumb/0/09/Cp_pride.png/64px-Cp_pride.png.webp",  
];

## UPO means: this is also a user preference option

## Database settings
$wgDBtype = "mysql";
$wgDBserver = getenv( 'MYSQL_SERVER' );
$wgDBname = getenv( 'MYSQL_DATABASE' );
$wgDBuser = getenv( 'MYSQL_USER' );
$wgDBpassword = getenv( 'MYSQL_PASSWORD' );
$wgDBssl = true;

# MySQL specific settings
$wgDBprefix = "mw_";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Shared database table
# This has no effect unless $wgSharedDB is also set.
$wgSharedTables[] = "actor";

$wgEnableEmail = true;
$wgEnableUserEmail = true; # UPO
$wgAllowHTMLEmail = true;

$wgPasswordSender = "wiki@coasterpedia.net";

$wgEnotifUserTalk = false; # UPO
$wgEnotifWatchlist = false; # UPO
$wgEmailAuthentication = true;
$wgSMTP = [
    'host'      => getenv( 'SMTP_HOST' ),    // could also be an IP address. Where the SMTP server is located. If using SSL or TLS, add the prefix "ssl://" or "tls://".
    'IDHost'    => 'coasterpedia.net',       // Generally this will be the domain name of your website (aka mywiki.org)
    'port'      => 587,                      // Port to use when connecting to the SMTP server
    'auth'      => true,                     // Should we use SMTP authentication (true or false)
    'username'  => getenv( 'SMTP_USER' ),    // Username to use for SMTP authentication (if being used)
    'password'  => getenv( 'SMTP_PASSWORD' ) // Password to use for SMTP authentication (if being used)
];

# AWS
$BUCKET = getenv( 'R2_BUCKET_NAME' );
$wgAWSRegion = "us-east-1"; // dummy value
// $wgAWSBucketName = getenv( 'AWS_BUCKET_NAME' );
$wgAWSBucketTopSubdirectory = "";
$wgAWSBucketDomain = 'images.coasterpedia.net';
$wgAWSRepoHashLevels = '2';
$wgAWSRepoDeletedHashLevels = '3';

$wgAWSCredentials = [
	'key' => getenv( 'R2_ACCESS_KEY' ),
	'secret' => getenv( 'R2_ACCESS_SECRET' ),
	'token' => false
];
$wgFileBackends['s3']['privateWiki'] = true;
$wgFileBackends['s3']['endpoint'] = getenv( 'R2_ENDPOINT' );
$wgUploadPath = "https://images.coasterpedia.net";
$wikiId = MediaWiki\WikiMap\WikiMap::getCurrentWikiId();
$wgFileBackends['s3']['containerPaths'] = [
	"$wikiId-local-public" => "{$BUCKET}",
	"$wikiId-local-thumb" => "{$BUCKET}/thumb",
	"$wikiId-local-deleted" => "{$BUCKET}/deleted",
	"$wikiId-local-temp" => "{$BUCKET}/temp"
];

$wgLocalFileRepo = [
	'class'             => 'LocalRepo',
	'name'              => 'local',
	'backend'           => 'AmazonS3',
	'url'               => $wgScriptPath . '/img_auth.php',
	'hashLevels'        => $wgAWSRepoHashLevels,
	'deletedHashLevels' => $wgAWSRepoDeletedHashLevels,
	'zones'             => [
		'public'  => [ 'url' => "https://images.coasterpedia.net" ],
		'thumb'   => [ 'url' => "https://images.coasterpedia.net/thumb" ],
		'temp'    => [ 'url' => false ],
		'deleted' => [ 'url' => false ]
	]
];
$wgImagePreconnect = true;
$wgNativeImageLazyLoading = true;

## Shared memory settings
$wgObjectCaches['redis'] = [
    'class' => 'RedisBagOStuff',
    'servers' => [ 'redis:6379' ],
    'persistent' => true,
];

$wgJobTypeConf['default'] = [
    'class' => 'JobQueueRedis',
    'redisServer' => 'redis:6379',
    'redisConfig' => [
        'connectTimeout' => 2,
        'compression' => 'gzip',
    ],
	'checkDelay' => true,
    'claimTTL' => 3600,
    'daemonized' => true
];
// We have jobrunner set up so don't run any jobs on request
$wgJobRunRate = 0;

$wgMainCacheType = 'redis';
$wgParserCacheType = 'redis';
$wgSessionCacheType = 'redis';
$wgMainStash = 'redis';

$wgEnableSidebarCache = true;
$wgUseLocalMessageCache = true;
$wgUseFileCache = false;

$wgObjectCacheSessionExpiry = 3600 * 3;

# Performance

$wgMiserMode = true;
$wgMultiShardSiteStats = true;
$wgInvalidateCacheOnLocalSettingsChange = false;
$wgDisableQueryPageUpdate = [
    'Deadendpages'
];
$wgUpdateRowsPerJob = 20;
$wgJobBackoffThrottling['htmlCacheUpdate'] = 5;

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = true;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";
$wgMaxImageArea = "3e7";
$wgIgnoreImageErrors = true;

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = false;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = false;

# Site language code, should be one of the list in ./includes/languages/data/Names.php
$wgLanguageCode = "en";

# Time zone
$wgLocaltimezone = "UTC";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
#$wgCacheDirectory = "$IP/cache";

$wgSecretKey = getenv( 'SECRET_KEY' );

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
#$wgUpgradeKey = "";

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "http://creativecommons.org/licenses/by-sa/4.0/";
$wgRightsText = "Creative Commons Attribution Share Alike";
$wgRightsIcon = "https://images.coasterpedia.net/0/0f/Badge-ccbysa.svg";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "/usr/bin/diff3";

# Search
// $wgDisableSearchUpdate = true;
$wgCirrusSearchServers = [ getenv( 'ES_IP' ) ];
$wgSearchType = 'CirrusSearch';
$wgCirrusSearchUseCompletionSuggester = 'yes';
$wgCirrusSearchCompletionSuggesterSubphrases = [
	'build'  => true,
	'use' => true,
	'type' => 'anywords',
	'limit' => 5,
];

## Default skin: you can change the default skin. Use the internal symbolic
## names, e.g. 'vector' or 'monobook':
$wgDefaultSkin = "citizen";

$wgCitizenEnableManifest = false;
$wgCitizenThemeColor = "#60AE26";
$wgCitizenManifestThemeColor = "#60AE26";
$wgCitizenOverflowNowrapClasses[] = 'infobox-new';
$wgCitizenOverflowNowrapClasses[] = 'cp-navbox';
$wgAllowSiteCSSOnRestrictedPages = true;
$wgCitizenSearchGateway = 'mwRestApi';
$wgCitizenSearchDescriptionSource = 'wikidata';
$wgCitizenMaxSearchResults = 10;

# Namespaces
$wgExtraNamespaces[3000] = "Draft";
$wgExtraNamespaces[3001] = "Draft_talk";
$wgNamespaceRobotPolicies[3000] = 'noindex';
$wgNamespaceRobotPolicies[3001] = 'noindex';


# Cargo
$wgCargoDBserver = getenv( 'CARGO_MYSQL_SERVER' );
$wgCargoDBname = getenv( 'CARGO_MYSQL_DATABASE' );
$wgCargoDBuser = getenv( 'CARGO_MYSQL_USER' );
$wgCargoDBpassword = getenv( 'CARGO_MYSQL_PASSWORD' );
$wgCargoDBprefix = "";

$wgCargoAllowedSQLFunctions[] = 'LENGTH';
$wgCargoAllowedSQLFunctions[] = 'REPLACE';
$wgCargoAllowedSQLFunctions[] = 'CURDATE';
$wgCargoAllowedSQLFunctions[] = 'RAND';
$wgCargoAllowedSQLFunctions[] = 'TIMESTAMPDIFF';

$wgCargoPageDataColumns[] = 'creationDate';
$wgCargoPageDataColumns[] = 'modificationDate';
$wgCargoPageDataColumns[] = 'categories';
$wgCargoPageDataColumns[] = 'isRedirect';
$wgCargoPageDataColumns[] = 'pageNameOrRedirect';
$wgCargoPageDataColumns[] = 'pageIDOrRedirect';

$wgCargoMapClusteringMinimum = 100;

##### MATOMO #####
$wgMatomoAnalyticsServerURL = "https://analytics.coasterpedia.net/";
$wgMatomoAnalyticsTokenAuth = getenv( 'MATOMO_API_KEY' );
$wgMatomoAnalyticsSiteID = 2;
$wgMatomoAnalyticsDisableCookie = true;

#### PLAUSIBLE ####
$wgPlausibleDomain = "https://analytics2.coasterpedia.net";  
$wgPlausibleDomainKey = "coasterpedia.net"; 

##### CAPTCHA #####
$wgCaptchaClass = MediaWiki\Extension\ConfirmEdit\Turnstile\Turnstile::class;
$wgCaptchaTriggers['create'] = true;
$wgTurnstileSiteKey= '0x4AAAAAABh32xrwXkf_zM90';
$wgTurnstileSecretKey= getenv( 'TURNSTILE_SECRET' );

# Upload Wizard
$wgApiFrameOptions = 'SAMEORIGIN';
$wgAllowCopyUploads = true;
$wgGroupPermissions['user']['upload_by_url'] = true; // to allow for all registered users
$wgUploadNavigationUrl = '/wiki/Special:UploadWizard';

$wgUploadDialog = [
	'fields' => [
		'description' => true,
		'date' => false,
		'categories' => false,
	],
	'licensemessages' => [
		'local' => 'generic-local',
		'foreign' => 'generic-foreign',
	],
	'comment' => '',
	'format' => [
		'filepage' => '$DESCRIPTION',
		'description' => '$TEXT',
		'ownwork' => '$SOURCE',
		'license' => '',
		'uncategorized' => '',
	],
];

# Footer
$wgFooterIcons["poweredby"]["mediawiki"] = [
	"src" => "https://images.coasterpedia.net/8/88/Badge-mediawiki.svg",
	"url" => "https://www.mediawiki.org/",
	"alt" => "Powered by MediaWiki",
];
$wgAllowUserCss = true;

# Visual Editor
$wgDefaultUserOptions['visualeditor-enable'] = 0; //Enable VisualEditor by default for everybody
$wgDefaultUserOptions['visualeditor-enable-experimental'] = 1; // OPTIONAL: Enable VisualEditor's experimental code features
$wgVisualEditorEnableWikitext = true; // Enable 2017 wikitext editor
$wgVisualEditorAvailableNamespaces = [
    "Help" => true,
    "User" => true,
    "User_talk" => true,
    "Talk" => true,
    "Coasterpedia" => true,
    "Draft" => true,
    "Category" => false
];
$wgForeignUploadTargets = []; // disable upload dialog in VisualEditor
$wgTemplateDataSuggestedValuesEditor = true;
$wgVisualEditorTransclusionDialogSuggestedValues = true;
$wgVisualEditorTransclusionDialogInlineDescriptions = true;
$wgVisualEditorTransclusionDialogBackButton = true;
$wgTemplateWizardTemplateSearchImprovements = true;
$wgVisualEditorTemplateSearchImprovements = true;
// Enable Edit Check
// @see https://www.mediawiki.org/wiki/Edit_check
$wgVisualEditorEditCheck = true;

$wgCodeMirrorAccessibilityColors = true;
$wgCodeMirrorEnableBracketMatching = true;
$wgCodeMirrorV6 = true;
$wgUploadWizardConfig = [
	'alternativeUploadToolsPage' => false, // Disable the link to alternative upload tools (default: points to Commons)
	'fileExtensions' => $wgFileExtensions, // omitting this may cause errors
	'tutorial' => [
		'skip' => true
	],
	'licensing' => [
		'defaultType' => 'ownwork'
	]
];
	
$wgFileExtensions = [ 'png', 'jpg', 'jpeg', 'svg', 'webp']; // remove GIF from upload options
$wgMaxUploadSize = 1024*1024*32;
$wgAllowTitlesInSVG = true;
$wgSVGNativeRendering = true;

$wgWikiEditorRealtimePreview = true;

// Autoconfirmed
$wgAutoConfirmAge = 86400; // one day
$wgAutoConfirmCount = 5;

# Citoid
$wgCitoidFullRestbaseURL = 'https://en.wikipedia.org/api/rest_';
$wgCitoidISBNScannerDesktopEnable = true;

# Details
// Disable custom handling since we only need to write <details> and <summary> in wikitext
$wgDetailsMWCollapsibleCompatibility = false;

# Disambiguator
$wgDisambiguatorNotifications = true;

# DismissableSiteNotice
$wgDismissableSiteNoticeForAnons = true;

# Echo
$wgEchoUseJobQueue = true;

# EmbedVideo
$wgEmbedVideoUseEmbedStyleForLocalVideos = false;

# Nearby
$wgMaxGeoSearchRadius = 1000000;
$wgNearbyRange = 1000000;
$wgNearbyPagesUrl = "https://coasterpedia.net/w/api.php";

# Kartographer
$wgKartographerMapServer = 'https://tile.openstreetmap.org';
$wgKartographerStyles = [''];
$wgKartographerDfltStyle = '';
$wgKartographerSrcsetScales = [ 1 ];
$wgKartographerSimpleStyleMarkers = false;
$wgKartographerStaticMapframe = false;
$wgKartographerWikivoyageMode = false;

// POPUPS Config
$wgPopupsHideOptInOnPreferencesPage = true;
$wgPopupsOptInDefaultState = '1';
$wgPopupsReferencePreviewsBetaFeature = false;

# Scribunto
$wgScribuntoDefaultEngine = 'luasandbox';

# Related Articles
$wgRelatedArticlesFooterWhitelistedSkins = [ 'citizen' ];
$wgRelatedArticlesDescriptionSource = 'wikidata';
$wgRelatedArticlesUseCirrusSearch = true;
$wgRelatedArticlesOnlyUseCirrusSearch = true;
$wgRelatedArticlesCardLimit = 6;

# OAuth
$wgOAuth2PrivateKey = getenv( 'OAUTH_PRIVATE' );
$wgOAuth2PublicKey = getenv( 'OAUTH_PUBLIC' );
$wgMWOAuthSessionCacheType = CACHE_DB;
$wgGroupPermissions['sysop']['mwoauthproposeconsumer'] = true;
$wgGroupPermissions['sysop']['mwoauthupdateownconsumer'] = true;
$wgGroupPermissions['sysop']['mwoauthmanageconsumer'] = true;
$wgGroupPermissions['sysop']['mwoauthsuppress'] = true;
$wgGroupPermissions['sysop']['mwoauthviewsuppressed'] = true;
$wgGroupPermissions['user']['mwoauthmanagemygrants'] = true;

# WikiDiff2
$isWikiDiff2Enabled = extension_loaded( 'wikidiff2' );
if ( $isWikiDiff2Enabled ) {
	$wgDiffEngine = 'wikidiff2';
}

# Security
$wgForceHTTPS = getenv( 'MW_FORCE_HTTPS' ) !== 'false';
// $wgBreakFrames = true;
// $wgCSPHeader = [
// 	// nonces have limited support and removed in MW 1.41
// 	'useNonces' => false,
// 	'script-src' => [
// 		'\'self\''
// 	],
// 	'default-src' => [
// 		'\'self\'',
// 		// Flickr API is required for UploadWizard
// 		'https://api.flickr.com'
// 	],
// 	'style-src' => ['\'self\'',],
// 	'object-src' => ['\'none\''],
// ];
$wgCookieSameSite = 'Lax';
// $wgCookieSecure = true;
$wgVaryOnXFP = true;
$wgPasswordDefault = 'argon2';

// Hide real name preference
$wgHiddenPrefs[] = 'realname';

# MultiPurge
$wgMultiPurgeEnabledServices = [ 'Cloudflare' ];
$wgMultiPurgeServiceOrder = $wgMultiPurgeEnabledServices;
$wgMultiPurgeCloudFlareZoneId = getenv( 'CLOUDFLARE_ZONEID' );
$wgMultiPurgeCloudFlareApiToken = getenv( 'CLOUDFLARE_APITOKEN' );
$wgMultiPurgeStaticPurges = [
	'Startup script' => 'load.php?lang=en&modules=startup&only=scripts&raw=1&skin=citizen',
	'Site styles' => 'load.php?lang=en&modules=site.styles&only=styles&skin=citizen'
];
$wgMultiPurgeRunInQueue = true;

# User rights
$wgGroupPermissions['autopatrolled']['autopatrol'] = true;
$wgGroupPermissions['patroller']['patrol'] = true;
// Block creating accounts using the API
$wgAPIModules['createaccount'] = 'ApiDisabled';

# External Data
// $wgExternalDataSources['api'] = [
// 	'url' => 'http://coasterpedia-api-coasterpediaapi-1:8080/$path$',
// 	'params' => [ 'path' ],
// 	'param filters' => [ 'path' => '/^([A-Za-z\/]*)/' ],
// 	'format' => 'JSON',
// ];

# EventBus
$wgEventServices = [
    'eventgate-main'  => ['url' => 'http://coasterpedia-legacy:8080/events']
];
$wgEnableEventBus = "TYPE_EVENT";
$wgEventStreams = [
    'my_stream' => [
        'producers' => [
            // EventBus specific settings go here:
            'mediawiki_eventbus' => [
                // Key of the event service in EventServices
                'event_service_name' => 'eventgate-main'
            ],
        ],
    ],
];
$wgEventServiceDefault = 'eventgate-main';

# Maps
$egMapsEnableCoordinateFunction = false;

# Other 
$wgPFEnableStringFunctions = true;
$wgDefaultUserOptions['multimediaviewer-enable'] = 1;
$wgAllowUserJs = true;
// $wgGenerateThumbnailOnParse = true;
$wgThumbnailSteps = [20, 40, 60, 120, 250, 330, 500, 960, 1280, 1920, 3840];
$wgFragmentMode = [ 'html5' ];
$wgNamespaceAliases['CP'] = NS_PROJECT;
$wgNamespaceAliases['CP_talk'] = NS_PROJECT_TALK;
$wgGroupPermissions['bot']['autopatrol'] = true;
$wgGroupPermissions['sysop']['autopatrol'] = true;
$wgGroupPermissions['sysop']['interwiki'] = true;
$wgGroupPermissions['sysop']['deletelogentry'] = true;
$wgGroupPermissions['sysop']['deleterevision'] = true;
$wgGroupPermissions['sysop']['thumbro-test'] = true;
$wgGroupPermissions['autoconfirmed']['upload'] = true;
$wgGroupPermissions['autoconfirmed']['skipcaptcha'] = true;
$wgGroupPermissions['user']['oathauth-enable'] = true;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['runcargoqueries'] = false;
$wgGroupPermissions['sysop']['runcargoqueries'] = true;
$wgGroupPermissions['bot']['replacetext'] = true;
$wgEnableEditRecovery = true;
$wgFixDoubleRedirects = true;
$wgGitRepositoryViewers['https://github.com/(.*?)(.git)?'] = 'https://github.com/$1/commit/%H';
$wgSitemapApiConfig['enabled'] = true;
$wgRestAPIAdditionalRouteFiles[] = 'includes/Rest/site.v1.json';
$wgMFStripResponsiveImages = false;

$wgMinervaEnableSiteNotice = true;

// $wgThumbroEnabled = false;
// $wgThumbroExposeTestPage = true;

// $wgGeoDataBackend = 'elastic';

## CDN Settings
$wgUseCdn = true;
$wgCdnMatchParameterOrder = false;
$wgUsePrivateIPs = true;
$wgCdnMaxAge = $wgParserCacheExpireTime;
$wgCdnServersNoPurge = [
	// Cloudflare
	'173.245.48.0/20',
	'103.21.244.0/22',
	'103.22.200.0/22',
	'103.31.4.0/22',
	'141.101.64.0/18',
	'108.162.192.0/18',
	'190.93.240.0/20',
	'188.114.96.0/20',
	'197.234.240.0/22',
	'198.41.128.0/17',
	'162.158.0.0/15',
	'104.16.0.0/13',
	'104.24.0.0/14',
	'172.64.0.0/13',
	'131.0.72.0/22',
	// Cloudflare IPv6
	'2400:cb00::/32',
	'2606:4700::/32',
	'2803:f800::/32',
	'2405:b500::/32',
	'2405:8100::/32',
	'2a06:98c0::/29',
	'2c0f:f248::/32',
	// Private IPs
	'172.16.0.0/12'
];

# Enabled skins.
wfLoadSkin( 'Citizen' );
wfLoadSkin( 'MinervaNeue' );
wfLoadSkin( 'MonoBook' );
wfLoadSkin( 'Refreshed' );
wfLoadSkin( 'Timeless' );
wfLoadSkin( 'Vector' );

# Extensions
wfLoadExtension( 'AbuseFilter' );
wfLoadExtension( 'AdvancedSearch' );
wfLoadExtension( 'AWS' );
wfLoadExtension( 'Babel' );
wfLoadExtension( 'Cargo' );
wfLoadExtension( 'CategoryTree' );
wfLoadExtension( 'CheckUser' );
wfLoadExtension( 'CirrusSearch' );
wfLoadExtension( 'Cite' );
wfLoadExtension( 'CiteThisPage' );
wfLoadExtension( 'Citoid' );
wfLoadExtension( 'CodeEditor' );
wfLoadExtension( 'CodeMirror' );
wfLoadExtension( 'CommonsMetadata' );
wfLoadExtensions([ 'ConfirmEdit', 'ConfirmEdit/Turnstile' ]);
wfLoadExtension( 'Details' );
wfLoadExtension( 'Disambiguator' );
wfLoadExtension( 'DiscussionTools' );
wfLoadExtension( 'DismissableSiteNotice' );
wfLoadExtension( 'DynamicPageList4' );
wfLoadExtension( 'Echo' );
wfLoadExtension( 'Elastica' );
wfLoadExtension( 'EventBus' );
wfLoadExtension( 'ExternalData' );
wfLoadExtension( 'EmbedVideo' );
wfLoadExtension( 'Gadgets' );
wfLoadExtension( 'GeoData' );
wfLoadExtension( 'ImageMap' );
wfLoadExtension( 'InputBox' );
wfLoadExtension( 'LabeledSectionTransclusion' );
wfLoadExtension( 'Linter' );
wfLoadExtension( 'LoginNotify' );
wfLoadExtension( 'Loops' );
wfLoadExtension( 'MatomoAnalytics' );
wfLoadExtension( 'MediaSearch' );
wfLoadExtension( 'MyVariables' );
wfLoadExtension( 'MultimediaViewer' );
wfLoadExtension( 'MultiPurge' );
wfLoadExtension( 'NearbyPages' );
wfLoadExtension( 'Nuke' );
wfLoadExtension( 'OATHAuth' );
wfLoadExtension( 'OAuth' );
wfLoadExtension( 'PageImages' );
wfLoadExtension( 'ParserFunctions' );
wfLoadExtension( 'PdfHandler' );
wfLoadExtension( 'Plausible' );
wfLoadExtension( 'Popups' );
wfLoadExtension( 'RelatedArticles' );
wfLoadExtension( 'ReplaceText' );
wfLoadExtension( 'RevisionSlider' );
wfLoadExtension( 'SandboxLink' );
wfLoadExtension( 'Scribunto' );
wfLoadExtension( 'ShortDescription' );
wfLoadExtension( 'SpamBlacklist' );
wfLoadExtension( 'SyntaxHighlight_GeSHi' );
wfLoadExtension( 'TabberNeue' );
wfLoadExtension( 'TemplateData' );
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateStylesExtender' );
wfLoadExtension( 'TextExtracts' );
wfLoadExtension( 'Thanks' );
wfLoadExtension( 'Thumbro' );
wfLoadExtension( 'TitleBlacklist' );
wfLoadExtension( 'TwoColConflict' );
wfLoadExtension( 'UploadWizard' );
wfLoadExtension( 'Variables' );
wfLoadExtension( 'VisualEditor' );
wfLoadExtension( 'WikiEditor' );

$wgHooks['ThumbnailBeforeProduceHTML'][] = function( $thumbnail, &$attribs, &$linkAttribs ) {
	/**
	 * Eager load the first image on the page
	 * Currently we don't have a reliable way to set which image,
	 * so we will just grab the first image with 400px as width,
	 * since it is used by infoboxes usually.
	 */

	/**
	 * Check if the image is a LCP image
	 * 1. Make sure that the image has the mw-file-description class
	 * 2. Make sure that the image has 400px image width (i.e. infobox image)
	 */
	$isLCPImage = strpos( $linkAttribs['class'] ?? '', 'mw-file-description' ) !== false &&
		$attribs['width'] === 400;
	if ( $isLCPImage ) {
		unset( $attribs['loading'] );
		$attribs['fetchpriority'] = 'high';
		$sctHasSetImageEager = true;
	}
	return true;
};

/** @see https://www.mediawiki.org/wiki/Manual:$wgNoFollowLinks */
$wgHooks['HtmlPageLinkRendererEnd'][] = function( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ) {
	// Append rel="nofollow" to red links to avoid unnecessary crawler traffic
	if ( !$isKnown && preg_match( '/\bnew\b/S', $attribs['class'] ?? '' ) ) {
        $attribs['rel'] = 'nofollow';
    }
    return true;
};

/**
 * Add links to the footer
 *
 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinAddFooterLinks
 */
$wgHooks['SkinAddFooterLinks'][] = function( $sk, $key, &$footerlinks ) {
	// Early edit
	if ( $key !== 'places' ) {
		return;
	}
	$rel = 'nofollow noreferrer noopener';
	$footerlinks[ 'cookiestatement' ] = MediaWiki\Html\Html::rawElement( 'a',
		[
			'href' => MediaWiki\Title\Title::newFromText(
				$sk->msg('cookiestatementpage')->inContentLanguage()->text()
			)->getFullURL()
		],
		$sk->msg('cookiestatement')->escaped()
	);
	$footerlinks['statuspage'] = MediaWiki\Html\Html::rawElement(
		'a',
		[
			'href' => 'https://status.coasterpedia.net',
			'rel' => $rel
		],
		$sk->msg('footer-statuspage')->escaped()
	);
	$footerlinks['github'] = MediaWiki\Html\Html::rawElement(
		'a',
		[
			'href' => 'https://github.com/Coasterpedia',
			'rel' => $rel
		],
		$sk->msg('footer-github')->escaped()
	);
	$footerlinks['kofi'] = MediaWiki\Html\Html::rawElement(
		'a',
		[
			'href' => 'https://ko-fi.com/coasterpedia',
			'rel' => $rel
		],
		$sk->msg('footer-kofi')->escaped()
	);
};
