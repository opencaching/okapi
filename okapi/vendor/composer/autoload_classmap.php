<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir);

return array(
    'okapi\\BadRequest' => $baseDir . '/core.php',
    'okapi\\Cache' => $baseDir . '/core.php',
    'okapi\\Db' => $baseDir . '/core.php',
    'okapi\\DbException' => $baseDir . '/core.php',
    'okapi\\DbInitException' => $baseDir . '/core.php',
    'okapi\\DbLockWaitTimeoutException' => $baseDir . '/core.php',
    'okapi\\DbTooManyRowsException' => $baseDir . '/core.php',
    'okapi\\Facade' => $baseDir . '/facade.php',
    'okapi\\FatalError' => $baseDir . '/core.php',
    'okapi\\FileCache' => $baseDir . '/core.php',
    'okapi\\Http404' => $baseDir . '/core.php',
    'okapi\\InvalidParam' => $baseDir . '/core.php',
    'okapi\\Okapi' => $baseDir . '/core.php',
    'okapi\\OkapiAccessToken' => $baseDir . '/core.php',
    'okapi\\OkapiConsumer' => $baseDir . '/core.php',
    'okapi\\OkapiDataStore' => $baseDir . '/datastore.php',
    'okapi\\OkapiDebugAccessToken' => $baseDir . '/core.php',
    'okapi\\OkapiDebugConsumer' => $baseDir . '/core.php',
    'okapi\\OkapiErrorHandler' => $baseDir . '/core.php',
    'okapi\\OkapiExceptionHandler' => $baseDir . '/core.php',
    'okapi\\OkapiFacadeAccessToken' => $baseDir . '/core.php',
    'okapi\\OkapiFacadeConsumer' => $baseDir . '/core.php',
    'okapi\\OkapiHttpRequest' => $baseDir . '/core.php',
    'okapi\\OkapiHttpResponse' => $baseDir . '/core.php',
    'okapi\\OkapiInternalAccessToken' => $baseDir . '/core.php',
    'okapi\\OkapiInternalConsumer' => $baseDir . '/core.php',
    'okapi\\OkapiInternalRequest' => $baseDir . '/core.php',
    'okapi\\OkapiLock' => $baseDir . '/core.php',
    'okapi\\OkapiOAuthServer' => $baseDir . '/core.php',
    'okapi\\OkapiRedirectResponse' => $baseDir . '/core.php',
    'okapi\\OkapiRequest' => $baseDir . '/core.php',
    'okapi\\OkapiRequestToken' => $baseDir . '/core.php',
    'okapi\\OkapiScriptEntryPointController' => $baseDir . '/OkapiScriptEntryPointController.php',
    'okapi\\OkapiServiceRunner' => $baseDir . '/OkapiServiceRunner.php',
    'okapi\\OkapiToken' => $baseDir . '/core.php',
    'okapi\\OkapiUrls' => $baseDir . '/OkapiUrls.php',
    'okapi\\OkapiZIPHttpResponse' => $baseDir . '/core.php',
    'okapi\\ParamMissing' => $baseDir . '/core.php',
    'okapi\\Settings' => $baseDir . '/settings.php',
    'okapi\\cronjobs\\AdminStatsSender' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\CacheCleanupCronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\ChangeLogCheckerJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\ChangeLogCleanerJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\ChangeLogWriterJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\CheckCronTab1' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\CheckCronTab2' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\Cron24Job' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\Cron5Job' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\CronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\CronJobController' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\FulldumpGeneratorJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\JobsAlreadyInProgress' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\LocaleChecker' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\OAuthCleanupCronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\PrerequestCronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\SearchSetsCleanerJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\StatsCompressorCronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\StatsWriterCronJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\TableOptimizerJob' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\TileTreeUpdater' => $baseDir . '/cronjobs.php',
    'okapi\\cronjobs\\TokenRevokerJob' => $baseDir . '/cronjobs.php',
    'okapi\\lib\\ClsTbsZip' => $baseDir . '/lib/ClsTbsZip.php',
    'okapi\\lib\\OCPLAccessLogs' => $baseDir . '/lib/OCPLAccessLogs.php',
    'okapi\\lib\\OCSession' => $baseDir . '/lib/OCSession.php',
    'okapi\\locale\\Locales' => $baseDir . '/locale/Locales.php',
    'okapi\\oauth\\OAuthClientException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthConsumer' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthDataStore' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthExpiredTimestampException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthInvalidConsumerException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthInvalidSignatureException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthInvalidTokenException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthMissingParameterException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthNonceAlreadyUsedException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthRequest' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthServer' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthServer400Exception' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthServer401Exception' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthServerException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthSignatureMethod' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthSignatureMethod_HMAC_SHA1' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthSignatureMethod_PLAINTEXT' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthSignatureMethod_RSA_SHA1' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthToken' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthUnsupportedSignatureMethodException' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthUtil' => $baseDir . '/oauth.php',
    'okapi\\oauth\\OAuthVersionNotSupportedException' => $baseDir . '/oauth.php',
    'okapi\\services\\apiref\\issue\\WebService' => $baseDir . '/services/apiref/issue/WebService.php',
    'okapi\\services\\apiref\\method\\WebService' => $baseDir . '/services/apiref/method/WebService.php',
    'okapi\\services\\apiref\\method_index\\WebService' => $baseDir . '/services/apiref/method_index/WebService.php',
    'okapi\\services\\apisrv\\installation\\WebService' => $baseDir . '/services/apisrv/installation/WebService.php',
    'okapi\\services\\apisrv\\installations\\WebService' => $baseDir . '/services/apisrv/installations/WebService.php',
    'okapi\\services\\apisrv\\stats\\WebService' => $baseDir . '/services/apisrv/stats/WebService.php',
    'okapi\\services\\attrs\\AttrHelper' => $baseDir . '/services/attrs/AttrHelper.php',
    'okapi\\services\\attrs\\attribute\\WebService' => $baseDir . '/services/attrs/attribute/WebService.php',
    'okapi\\services\\attrs\\attribute_index\\WebService' => $baseDir . '/services/attrs/attribute_index/WebService.php',
    'okapi\\services\\attrs\\attributes\\WebService' => $baseDir . '/services/attrs/attributes/WebService.php',
    'okapi\\services\\caches\\formatters\\garmin\\WebService' => $baseDir . '/services/caches/formatters/garmin/WebService.php',
    'okapi\\services\\caches\\formatters\\ggz\\WebService' => $baseDir . '/services/caches/formatters/ggz/WebService.php',
    'okapi\\services\\caches\\formatters\\gpx\\WebService' => $baseDir . '/services/caches/formatters/gpx/WebService.php',
    'okapi\\services\\caches\\geocache\\WebService' => $baseDir . '/services/caches/geocache/WebService.php',
    'okapi\\services\\caches\\geocaches\\WebService' => $baseDir . '/services/caches/geocaches/WebService.php',
    'okapi\\services\\caches\\map\\ReplicateListener' => $baseDir . '/services/caches/map/ReplicateListener.php',
    'okapi\\services\\caches\\map\\TileRenderer' => $baseDir . '/services/caches/map/TileRenderer.php',
    'okapi\\services\\caches\\map\\TileTree' => $baseDir . '/services/caches/map/TileTree.php',
    'okapi\\services\\caches\\map\\tile\\WebService' => $baseDir . '/services/caches/map/tile/WebService.php',
    'okapi\\services\\caches\\mark\\WebService' => $baseDir . '/services/caches/mark/WebService.php',
    'okapi\\services\\caches\\save_personal_notes\\WebService' => $baseDir . '/services/caches/save_personal_notes/WebService.php',
    'okapi\\services\\caches\\search\\SearchAssistant' => $baseDir . '/services/caches/search/SearchAssistant.php',
    'okapi\\services\\caches\\search\\all\\WebService' => $baseDir . '/services/caches/search/all/WebService.php',
    'okapi\\services\\caches\\search\\bbox\\WebService' => $baseDir . '/services/caches/search/bbox/WebService.php',
    'okapi\\services\\caches\\search\\by_urls\\WebService' => $baseDir . '/services/caches/search/by_urls/WebService.php',
    'okapi\\services\\caches\\search\\nearest\\WebService' => $baseDir . '/services/caches/search/nearest/WebService.php',
    'okapi\\services\\caches\\search\\save\\WebService' => $baseDir . '/services/caches/search/save/WebService.php',
    'okapi\\services\\caches\\shortcuts\\search_and_retrieve\\WebService' => $baseDir . '/services/caches/shortcuts/search_and_retrieve/WebService.php',
    'okapi\\services\\logs\\entries\\WebService' => $baseDir . '/services/logs/entries/WebService.php',
    'okapi\\services\\logs\\entry\\WebService' => $baseDir . '/services/logs/entry/WebService.php',
    'okapi\\services\\logs\\images\\LogImagesCommon' => $baseDir . '/services/logs/images/LogImagesCommon.php',
    'okapi\\services\\logs\\images\\add\\CannotPublishException' => $baseDir . '/services/logs/images/add/WebService.php',
    'okapi\\services\\logs\\images\\add\\WebService' => $baseDir . '/services/logs/images/add/WebService.php',
    'okapi\\services\\logs\\images\\delete\\WebService' => $baseDir . '/services/logs/images/delete/WebService.php',
    'okapi\\services\\logs\\images\\edit\\CannotPublishException' => $baseDir . '/services/logs/images/edit/WebService.php',
    'okapi\\services\\logs\\images\\edit\\WebService' => $baseDir . '/services/logs/images/edit/WebService.php',
    'okapi\\services\\logs\\logs\\WebService' => $baseDir . '/services/logs/logs/WebService.php',
    'okapi\\services\\logs\\submit\\CannotPublishException' => $baseDir . '/services/logs/submit/WebService.php',
    'okapi\\services\\logs\\submit\\WebService' => $baseDir . '/services/logs/submit/WebService.php',
    'okapi\\services\\logs\\userlogs\\WebService' => $baseDir . '/services/logs/userlogs/WebService.php',
    'okapi\\services\\oauth\\access_token\\WebService' => $baseDir . '/services/oauth/access_token/WebService.php',
    'okapi\\services\\oauth\\authorize\\WebService' => $baseDir . '/services/oauth/authorize/WebService.php',
    'okapi\\services\\oauth\\request_token\\WebService' => $baseDir . '/services/oauth/request_token/WebService.php',
    'okapi\\services\\replicate\\ReplicateCommon' => $baseDir . '/services/replicate/ReplicateCommon.php',
    'okapi\\services\\replicate\\changelog\\WebService' => $baseDir . '/services/replicate/changelog/WebService.php',
    'okapi\\services\\replicate\\fulldump\\WebService' => $baseDir . '/services/replicate/fulldump/WebService.php',
    'okapi\\services\\replicate\\info\\WebService' => $baseDir . '/services/replicate/info/WebService.php',
    'okapi\\services\\users\\by_internal_id\\WebService' => $baseDir . '/services/users/by_internal_id/WebService.php',
    'okapi\\services\\users\\by_internal_ids\\WebService' => $baseDir . '/services/users/by_internal_ids/WebService.php',
    'okapi\\services\\users\\by_username\\WebService' => $baseDir . '/services/users/by_username/WebService.php',
    'okapi\\services\\users\\by_usernames\\WebService' => $baseDir . '/services/users/by_usernames/WebService.php',
    'okapi\\services\\users\\user\\WebService' => $baseDir . '/services/users/user/WebService.php',
    'okapi\\services\\users\\users\\WebService' => $baseDir . '/services/users/users/WebService.php',
    'okapi\\views\\apps\\authorize\\View' => $baseDir . '/views/apps/authorize.php',
    'okapi\\views\\apps\\authorized\\View' => $baseDir . '/views/apps/authorized.php',
    'okapi\\views\\apps\\index\\View' => $baseDir . '/views/apps/index.php',
    'okapi\\views\\apps\\revoke_access\\View' => $baseDir . '/views/apps/revoke_access.php',
    'okapi\\views\\changelog\\Changelog' => $baseDir . '/views/changelog_helper.inc.php',
    'okapi\\views\\changelog\\View' => $baseDir . '/views/changelog.php',
    'okapi\\views\\changelog_feed\\View' => $baseDir . '/views/changelog_feed.php',
    'okapi\\views\\cron5\\View' => $baseDir . '/views/cron5.php',
    'okapi\\views\\devel\\attrlist\\View' => $baseDir . '/views/devel/attrlist.php',
    'okapi\\views\\devel\\clogentry\\View' => $baseDir . '/views/devel/clogentry.php',
    'okapi\\views\\devel\\cronreport\\View' => $baseDir . '/views/devel/cronreport.php',
    'okapi\\views\\devel\\dbstruct\\View' => $baseDir . '/views/devel/dbstruct.php',
    'okapi\\views\\devel\\sysinfo\\View' => $baseDir . '/views/devel/sysinfo.php',
    'okapi\\views\\devel\\tilereport\\View' => $baseDir . '/views/devel/tilereport.php',
    'okapi\\views\\examples\\View' => $baseDir . '/views/examples.php',
    'okapi\\views\\http404\\View' => $baseDir . '/views/http404.php',
    'okapi\\views\\index\\View' => $baseDir . '/views/index.php',
    'okapi\\views\\introduction\\View' => $baseDir . '/views/introduction.php',
    'okapi\\views\\menu\\OkapiMenu' => $baseDir . '/views/menu.inc.php',
    'okapi\\views\\method_call\\View' => $baseDir . '/views/method_call.php',
    'okapi\\views\\method_doc\\View' => $baseDir . '/views/method_doc.php',
    'okapi\\views\\signup\\View' => $baseDir . '/views/signup.php',
    'okapi\\views\\tilestress\\View' => $baseDir . '/views/tilestress.php',
    'okapi\\views\\update\\View' => $baseDir . '/views/update.php',
);
