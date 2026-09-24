<?php
// Loaded via jQuery.getScript() (indexer.js), so the whole response is executed as
// JavaScript - every exit path must emit valid JS. A bare die('Invalid token') used to
// surface as "Uncaught SyntaxError: Unexpected identifier 'token'".
if (!isset($_GET['cid']) || !isset($_GET['token']))
	die('/* Invalid request */');


define('JS_OUTPUT',1);
define('CID',$_GET['cid']);
define('READ_ONLY_SESSION',true);
require_once('../../../include.php');
// Session gone (expired, logged out, memcached flushed) or a stale tab still polling with
// an old session's token: stop the polling loop quietly by not rescheduling it. The next
// process.php request handles the expired session itself.
if(!isset($_SESSION['rb_indexer_token']) || $_SESSION['rb_indexer_token']!=$_GET['token'])
    die('/* Invalid token */');

$total = 0;
if(@file_get_contents(DATA_DIR.'/Utils_RecordBrowser/last')<time()-120) {
    ModuleManager::load_modules();
    Base_AclCommon::set_sa_user();

    Utils_RecordBrowserCommon::indexer(30,$total);
    if($total==0) file_put_contents(DATA_DIR.'/Utils_RecordBrowser/last',time(),LOCK_EX);
}
?>setTimeout('rb_indexer("<?php print($_GET['token']); ?>")',<?php print($total==0?180000:10000);?>);