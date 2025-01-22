<?php
# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// $wgReadOnly = ( PHP_SAPI === 'cli' ) ? false : 'This wiki is currently being upgraded to a newer software version. Please check back in a couple of hours.';

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
$wgServer = "https://coasterpedia.net";
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
$wgLogo = "https://images.coasterpedia.net/0/09/Cp_pride.png";
$wgLogos = [];

## UPO means: this is also a user preference option

## Database settings
$wgDBtype = "mysql";
$wgDBserver = getenv( 'MYSQL_SERVER' );
$wgDBname = getenv( 'MYSQL_DATABASE' );
$wgDBuser = getenv( 'MYSQL_USER' );
$wgDBpassword = getenv( 'MYSQL_PASSWORD' );

# MySQL specific settings
$wgDBprefix = "mw_";
$wgDBssl = false;

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Shared database table
# This has no effect unless $wgSharedDB is also set.
$wgSharedTables[] = "actor";

$wgEnableEmail = true;
$wgEnableUserEmail = true; # UPO

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
$BUCKET = getenv( 'AWS_BUCKET_NAME' );
$wgAWSRegion = getenv( 'AWS_REGION' );
// $wgAWSBucketName = getenv( 'AWS_BUCKET_NAME' );
$wgAWSBucketTopSubdirectory="";
$wgAWSBucketDomain = 'images.coasterpedia.net';
$wgAWSRepoHashLevels = '2';
$wgAWSRepoDeletedHashLevels = '3';

$wgFileBackends['s3']['privateWiki'] = true;
$wgUploadPath = "https://images.coasterpedia.net";
$wikiId = WikiMap::getCurrentWikiId();
$wgFileBackends['s3']['containerPaths'] = [
	"$wikiId-local-public" => "${BUCKET}",
	"$wikiId-local-thumb" => "${BUCKET}/thumb",
	"$wikiId-local-deleted" => "${BUCKET}/deleted",
	"$wikiId-local-temp" => "${BUCKET}/temp"
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


$wgMainCacheType = 'redis';
$wgParserCacheType = CACHE_DB;
$wgSessionCacheType = 'redis';
$wgMainStash = 'redis';

$wgEnableSidebarCache = true;
$wgUseLocalMessageCache = true;
$wgUseFileCache = false;

$wgParserCacheExpireTime = 2592000;
$wgObjectCacheSessionExpiry = 3600 * 3;

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = true;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";
$wgMaxImageArea = "3e7";

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

$wgCitizenThemeColor = "#60AE26";
$wgCitizenManifestThemeColor = "#60AE26";
$wgCitizenOverflowNowrapClasses[] = 'infobox-new';
$wgCitizenOverflowNowrapClasses[] = 'cp-navbox';
$wgAllowSiteCSSOnRestrictedPages = true;
$wgCitizenSearchGateway = 'mwRestApi';
$wgCitizenSearchDescriptionSource = 'wikidata';
$wgCitizenMaxSearchResults = 10;

# Cargo
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

##### MATOMO #####
$wgMatomoURL = "analytics.coasterpedia.net";
$wgMatomoIDSite = "2";
$wgMatomoTrackUsernames = true;
$wgMatomoIgnoreSysops = false;

##### CAPTCHA #####
$wgCaptchaClass = 'QuestyCaptcha';
$wgCaptchaTriggers['create'] = true; 
// Add your questions in LocalSettings.php using this format
$wgCaptchaQuestions[] = array( 'question' => "In which year did the UK theme park Alton Towers open?", 'answer' => "1980");
$wgCaptchaQuestions[] = array( 'question' => 'In which year did the US theme park Six Flags AstroWorld close?', 'answer' => '2005' );
$wgCaptchaQuestions[] = array( 'question' => "In which US state does the theme park Busch Gardens Tampa Bay operate in?", 'answer' => "Florida" );
$wgCaptchaQuestions[] = array( 'question' => "In which country does the roller coaster Steel Dragon 2000 operate in?", 'answer' => "Japan" );
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
    "Coasterpedia" => true
];
$wgForeignUploadTargets = []; // disable upload dialog in VisualEditor
$wgTemplateDataSuggestedValuesEditor = true;
$wgVisualEditorTransclusionDialogSuggestedValues = true;
$wgVisualEditorTransclusionDialogInlineDescriptions = true;
$wgVisualEditorTransclusionDialogBackButton = true;
$wgTemplateWizardTemplateSearchImprovements = true;
$wgVisualEditorTemplateSearchImprovements = true;
$wgCodeMirrorAccessibilityColors = true;
$wgCodeMirrorEnableBracketMatching = true;
$wgUploadWizardConfig = [
	'alternativeUploadToolsPage' => false, // Disable the link to alternative upload tools (default: points to Commons)
	'fileExtensions' => $wgFileExtensions, // omitting this may cause errors
	'tutorial' => [
		'skip' => true
	]
];
$wgFileExtensions = [ 'png', 'jpg', 'jpeg', 'svg', 'webp']; // remove GIF from upload options
$wgMaxUploadSize = 1024*1024*32;

$wgWikiEditorRealtimePreview = true;

// Autoconfirmed
$wgAutoConfirmAge = 86400; // one day
$wgAutoConfirmCount = 5;

# Citoid
$wgCitoidFullRestbaseURL = 'https://en.wikipedia.org/api/rest_';
$wgCitoidISBNScannerDesktopEnable = true;

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

# External Data
$wgExternalDataSources['api'] = [
	'url' => 'http://coasterpedia-api-coasterpediaapi-1:8080/$path$',
	'params' => [ 'path' ],
	'param filters' => [ 'path' => '/^([A-Za-z\/]*)/' ],
	'format' => 'JSON',
];

# Other 
$wgPFEnableStringFunctions = true;
$wgDefaultUserOptions['multimediaviewer-enable'] = 1;
$wgAllowUserJs = true;
$wgGenerateThumbnailOnParse = true;
$wgFragmentMode = [ 'html5', 'legacy' ];
$wgNamespaceAliases['CP'] = NS_PROJECT;
$wgNamespaceAliases['CP_talk'] = NS_PROJECT_TALK;
$wgGroupPermissions['bot']['autopatrol'] = true;
$wgGroupPermissions['sysop']['autopatrol'] = true;
$wgGroupPermissions['sysop']['interwiki'] = true;
$wgGroupPermissions['sysop']['deletelogentry'] = true;
$wgGroupPermissions['sysop']['deleterevision'] = true;
$wgGroupPermissions['autoconfirmed']['upload'] = true;
$wgGroupPermissions['autoconfirmed']['skipcaptcha'] = true;
$wgGroupPermissions['*']['edit'] = false;

$wgMFStripResponsiveImages = false;

$wgMinervaEnableSiteNotice = true;

// $wgGeoDataBackend = 'elastic';

## CDN Settings
$wgUseCdn = true;
$wgCdnServers = [ '127.0.0.1' ];
$wgCdnServersNoPurge = [
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
'2400:cb00::/32',
'2606:4700::/32',
'2803:f800::/32',
'2405:b500::/32',
'2405:8100::/32',
'2a06:98c0::/29',
'2c0f:f248::/32',
'120.52.22.96/27',
'205.251.249.0/24',
'180.163.57.128/26',
'204.246.168.0/22',
'111.13.171.128/26',
'18.160.0.0/15',
'205.251.252.0/23',
'54.192.0.0/16',
'204.246.173.0/24',
'54.230.200.0/21',
'120.253.240.192/26',
'116.129.226.128/26',
'130.176.0.0/17',
'108.156.0.0/14',
'99.86.0.0/16',
'13.32.0.0/15',
'120.253.245.128/26',
'13.224.0.0/14',
'70.132.0.0/18',
'15.158.0.0/16',
'111.13.171.192/26',
'13.249.0.0/16',
'18.238.0.0/15',
'18.244.0.0/15',
'205.251.208.0/20',
'3.165.0.0/16',
'3.168.0.0/14',
'65.9.128.0/18',
'130.176.128.0/18',
'58.254.138.0/25',
'205.251.201.0/24',
'205.251.206.0/23',
'54.230.208.0/20',
'3.160.0.0/14',
'116.129.226.0/25',
'52.222.128.0/17',
'18.164.0.0/15',
'111.13.185.32/27',
'64.252.128.0/18',
'205.251.254.0/24',
'3.166.0.0/15',
'54.230.224.0/19',
'71.152.0.0/17',
'216.137.32.0/19',
'204.246.172.0/24',
'205.251.202.0/23',
'18.172.0.0/15',
'120.52.39.128/27',
'118.193.97.64/26',
'3.164.64.0/18',
'18.154.0.0/15',
'54.240.128.0/18',
'205.251.250.0/23',
'180.163.57.0/25',
'52.46.0.0/18',
'52.82.128.0/19',
'54.230.0.0/17',
'54.230.128.0/18',
'54.239.128.0/18',
'130.176.224.0/20',
'36.103.232.128/26',
'52.84.0.0/15',
'143.204.0.0/16',
'144.220.0.0/16',
'120.52.153.192/26',
'119.147.182.0/25',
'120.232.236.0/25',
'111.13.185.64/27',
'3.164.0.0/18',
'54.182.0.0/16',
'58.254.138.128/26',
'120.253.245.192/27',
'54.239.192.0/19',
'18.68.0.0/16',
'18.64.0.0/14',
'120.52.12.64/26',
'99.84.0.0/16',
'205.251.204.0/23',
'130.176.192.0/19',
'52.124.128.0/17',
'205.251.200.0/24',
'204.246.164.0/22',
'13.35.0.0/16',
'204.246.174.0/23',
'3.164.128.0/17',
'3.172.0.0/18',
'36.103.232.0/25',
'119.147.182.128/26',
'118.193.97.128/25',
'120.232.236.128/26',
'204.246.176.0/20',
'65.8.0.0/16',
'65.9.0.0/17',
'108.138.0.0/15',
'120.253.241.160/27',
'64.252.64.0/18',
'13.113.196.64/26',
'13.113.203.0/24',
'52.199.127.192/26',
'13.124.199.0/24',
'3.35.130.128/25',
'52.78.247.128/26',
'13.233.177.192/26',
'15.207.13.128/25',
'15.207.213.128/25',
'52.66.194.128/26',
'13.228.69.0/24',
'52.220.191.0/26',
'13.210.67.128/26',
'13.54.63.128/26',
'43.218.56.128/26',
'43.218.56.192/26',
'43.218.56.64/26',
'43.218.71.0/26',
'99.79.169.0/24',
'18.192.142.0/23',
'18.199.68.0/22',
'18.199.72.0/22',
'18.199.76.0/22',
'35.158.136.0/24',
'52.57.254.0/24',
'13.48.32.0/24',
'18.200.212.0/23',
'52.212.248.0/26',
'3.10.17.128/25',
'3.11.53.0/24',
'52.56.127.0/25',
'15.188.184.0/24',
'52.47.139.0/24',
'3.29.40.128/26',
'3.29.40.192/26',
'3.29.40.64/26',
'3.29.57.0/26',
'18.229.220.192/26',
'54.233.255.128/26',
'3.231.2.0/25',
'3.234.232.224/27',
'3.236.169.192/26',
'3.236.48.0/23',
'34.195.252.0/24',
'34.226.14.0/24',
'44.222.66.0/24',
'13.59.250.0/26',
'18.216.170.128/25',
'3.128.93.0/24',
'3.134.215.0/24',
'3.146.232.0/22',
'3.147.164.0/22',
'3.147.244.0/22',
'52.15.127.128/26',
'3.101.158.0/23',
'52.52.191.128/26',
'34.216.51.0/25',
'34.223.12.224/27',
'34.223.80.192/26',
'35.162.63.192/26',
'35.167.191.128/26',
'44.227.178.0/24',
'44.234.108.128/25',
'44.234.90.252/30'
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
wfLoadExtension( 'CirrusSearch' );
wfLoadExtension( 'Cite' );
wfLoadExtension( 'CiteThisPage' );
wfLoadExtension( 'Citoid' );
wfLoadExtension( 'CodeEditor' );
wfLoadExtension( 'CodeMirror' );
wfLoadExtension( 'CommonsMetadata' );
wfLoadExtensions([ 'ConfirmEdit', 'ConfirmEdit/QuestyCaptcha' ]);
wfLoadExtension( 'Disambiguator' );
wfLoadExtension( 'DiscussionTools' );
wfLoadExtension( 'DismissableSiteNotice' );
wfLoadExtension( 'DynamicPageList3' );
wfLoadExtension( 'Echo' );
wfLoadExtension( 'Elastica' );
wfLoadExtension( 'ExternalData' );
wfLoadExtension( 'EmbedVideo' );
wfLoadExtension( 'Gadgets' );
wfLoadExtension( 'GeoData' );
wfLoadExtension( 'ImageMap' );
wfLoadExtension( 'InputBox' );
wfLoadExtension( 'Interwiki' );
wfLoadExtension( 'JsonConfig' );
wfLoadExtension( 'Kartographer' );
wfLoadExtension( 'Linter' );
wfLoadExtension( 'LoginNotify' );
wfLoadExtension( 'Loops' );
wfLoadExtension( 'Matomo' );
wfLoadExtension( 'MediaSearch' );
wfLoadExtension( 'MyVariables' );
wfLoadExtension( 'MultimediaViewer' );
wfLoadExtension( 'MultiPurge' );
wfLoadExtension( 'NativeSvgHandler' );
wfLoadExtension( 'NearbyPages' );
wfLoadExtension( 'Nuke' );
wfLoadExtension( 'OAuth' );
wfLoadExtension( 'PageImages' );
wfLoadExtension( 'ParserFunctions' );
wfLoadExtension( 'PdfHandler' );
wfLoadExtension( 'Popups' );
wfLoadExtension( 'RelatedArticles' );
wfLoadExtension( 'RevisionSlider' );
wfLoadExtension( 'SandboxLink' );
wfLoadExtension( 'Scribunto' );
wfLoadExtension( 'ShortDescription' );
wfLoadExtension( 'SpamBlacklist' );
wfLoadExtension( 'SyntaxHighlight_GeSHi' );
wfLoadExtension( 'TemplateData' );
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateStylesExtender' );
wfLoadExtension( 'TextExtracts' );
wfLoadExtension( 'Thanks' );
wfLoadExtension( 'TitleBlacklist' );
wfLoadExtension( 'TwoColConflict' );
wfLoadExtension( 'UploadWizard' );
wfLoadExtension( 'Variables' );
wfLoadExtension( 'VisualEditor' );
wfLoadExtension( 'WikiEditor' );
