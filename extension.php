<?php namespace tagcloud;
if (!defined("INDEXED")) exit;
if (defined("AJAX")) return;

$extensiondir = "extensions/" . getExtensionName(__DIR__) . "/";
$ext = getExtensionName(__DIR__);

if ($extension_config[$ext]["add_to_homepage"]) {
    $custom_pages["homepage"]["data"] = substr_replace($custom_pages["homepage"]["data"], ',"tagcloud":{}', strpos($custom_pages["homepage"]["data"],","), 0);
}

$tagtablecheck = $db->query("SHOW TABLES like 'tags'");
if ($tagtablecheck->num_rows < 1)
{
    $db->query("CREATE TABLE tags (
    tag varchar(64) NOT NULL,
    threadid int unsigned NOT NULL,
    PRIMARY KEY (tag, threadid),
    CONSTRAINT fk_thread
    FOREIGN KEY (threadid)
    REFERENCES threads(threadid)
    ON DELETE CASCADE
); ");
}

function addStyles() {
    echo "<style>.tdtags {margin-left:1em;} #edittags:focus {border: var(--c-gradient-bottom) 1px solid; padding: 2px 2px;} #edittags {border:unset;background:unset;margin-right:2px;padding:3px;padding-left:0px;} .edit_tag_container {width:350px;display:inline-grid;grid-template-columns:1fr auto;margin-bottom:2px} .tagcloud {margin-bottom:20px;text-align:center;line-height:21px} .tagcloud > * {margin-right:1em;overflow-wrap:break-word;color:var(--c-gradient-bottom-darker);} .t0 {font-size:12px;opacity:0.75;} .t1 {font-size:13px;opacity:0.75;} .t2 {font-size:15px;opacity:0.85;} .t3 {font-size:17px;opacity:0.9;} .t4 {font-size:19px;}</style>";
}
function generator_tagcloud() {
    global $db, $ext, $extension_config;
    $tagcloud = "<div class='tagcloud'>";
    $tag_query = $db->query("SELECT * FROM (SELECT tt.tag, tt.threadid, COUNT(tt.tag) AS count FROM tags tt LEFT JOIN threads t ON (tt.threadid=t.threadid) WHERE t.draft=0 GROUP BY tt.tag ORDER BY COUNT(tt.tag) DESC LIMIT ".$extension_config[$ext]["tag_cloud_size"].") AS z ORDER BY threadid ASC");
    $tags = [];
    $c = 0;
    while ($row = $tag_query->fetch_assoc()) {
        $tags[] = [$row["tag"],$row["count"]];
        if ($row["count"] > $c) $c = $row["count"];
    }
    $c = max($c,5);
    foreach ($tags as $t) {
        $tagcloud .= "<a class='t".floor(($t[1]/$c)*4)."' href='".genURL("search?tags=".htmlspecialchars(urlencode($t[0])))."'>".format($t[0])."</a>";
    }
    $tagcloud .= "</div>";
    return $tagcloud;
}

function getTagsForThread($threadid, $aslinks=false) {
    global $db;
    $tquery = $db->query("SELECT * FROM tags WHERE threadid=$threadid");
    $tags = "";
    while ($row = $tquery->fetch_assoc()) {
        if ($aslinks) $tags .= "<a href='".genURL("search?tags=".htmlspecialchars(urlencode($row["tag"])))."'>" . format($row["tag"]) . "</a>, ";
        else $tags .= htmlspecialchars($row["tag"]) . ", ";
    }
    return rtrim($tags,", ");
}

function getValidTagsFromString($string) {
    $tags = explode(",",$string);
    foreach($tags as &$t) {
        $t = trim(strtolower($t));
    }
    $tags = array_filter($tags, function ($x) {
        global $ext, $extension_config;
        if ($extension_config[$ext]["proper_gerunds"]) {
            if (substr_compare($x, "ing", -3)) return false;
        }
        return strlen($x) && (strlen($x) < 64);
    });
    return $tags;
}

function addEditTagForm(&$args) {
    global $author, $viewerid, $template, $extensiondir, $db, $q2, $extension_config, $ext;
    if ($args[0] != "templates/thread/thread.html") return;
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST["edittags"])) {
        if (($author["userid"] == $viewerid and get_role_permissions() & PERM_CREATE_THREAD) or get_role_permissions() & PERM_EDIT_THREAD) {
            $db->query("DELETE FROM tags WHERE threadid='" . $db->real_escape_string($q2) . "'");
            $tags = getValidTagsFromString($_POST["edittags"]);
            $c = 0;
            foreach ($tags as $t) {
                $c++;
                if ($c > $extension_config[$ext]["max_tags_per_thread"]) break;
                $db->query("REPLACE INTO tags VALUES('".$db->real_escape_string($t)."','".$db->real_escape_string($q2)."')");
            }
        }
    }
    $data = &$args[1];
    $tdata = ["tags"=>"","submit"=>"Save tags"];
    if (($author["userid"] == $viewerid and get_role_permissions() & PERM_CREATE_THREAD) or get_role_permissions() & PERM_EDIT_THREAD) {
        $tdata["tags"] = getTagsForThread($db->real_escape_string($q2));
        $tags = $template->render($extensiondir . "templates/thread_tags_edit.html",$tdata);
    }
    else {
        $tdata["tags"] = getTagsForThread($db->real_escape_string($q2),true);
        $tags = $template->render($extensiondir . "templates/thread_tags.html",$tdata);
    }
    $data["labels"] = "<div>Tags: $tags</div>" . $data["labels"];
}

function addTagsToNewThread(&$args) {
    global $db, $extension_config, $ext;
    $tags = getValidTagsFromString($_POST["tags"]);
    $c = 0;
    foreach ($tags as $t) {
        $c++;
        if ($c > $extension_config[$ext]["max_tags_per_thread"]) return;
        $db->query("REPLACE INTO tags VALUES('".$db->real_escape_string($t)."','".$db->real_escape_string($args[0])."')");
    }
}

function addTagFormToNewThread(&$args) {
    global $author, $viewerid, $template, $extensiondir, $db, $q2;
    if ($args[0] != "templates/newthread.html") return;
    
    $data = &$args[1];
    $data["bbcode_tray"] = "<div class='forminput'><label>Tags:</label><input name='tags' value='".@htmlspecialchars(@$_POST["tags"])."'></div>" . $data["bbcode_tray"];
}

function addTagFormToSearch(&$args) {
    global $author, $viewerid, $template, $extensiondir, $db, $q2;
    if ($args[0] != "templates/search/search_page.html") return;
    
    $data = &$args[1];
    $data["extra_options"] .= "<div class='forminput'><label class='label'>Tags:</label><input name='tags' value='".@htmlspecialchars(@$_GET["tags"])."'></div>";
}

function addTagsToThreadDisplay(&$args) {
    if ($args[0] != "templates/thread/thread_display.html") return;
    $data = &$args[1];
    $data["startuser"] .= "<span class='tdtags'>" . getTagsForThread($data["threadid"],true) . "</span>";
}

function addTagsToSearchResults(&$args) {
    global $db;
    $get = &$args[2];
    $and = &$args[1];
    $query = &$args[0];
    if (!isset($get["tags"]) || $get["tags"] == null) return;
    $tags = getValidTagsFromString(urldecode(htmlspecialchars_decode($get["tags"])));
    if (!count($tags)) return;
    foreach ($tags as &$tag) {
        $tag = "'".$db->real_escape_string($tag)."'";
    }
    addToQuery("EXISTS (SELECT 1 FROM tags WHERE tags.threadid = threads.threadid AND tags.tag in (".implode(",",$tags)."))", $query, $and);
}

$widgets["tagcloud"] = "tagcloud\generator_tagcloud";


// Hook the functions
hook("meta", "tagcloud\addStyles");
if ($q1 == "thread") {
    hook("beforeTemplateRender","tagcloud\addEditTagForm");
}
if ($q1 == "newthread") {
    hook("beforeTemplateRender","tagcloud\addTagFormToNewThread");
    hook("onCreateThread","tagcloud\addTagsToNewThread");
}
if ($q1 == "search") {
    hook("beforeTemplateRender","tagcloud\addTagFormToSearch");
}
hook("buildSearchQueryBeforeAddOrder","tagcloud\addTagsToSearchResults");
hook("beforeTemplateRender","tagcloud\addTagsToThreadDisplay");
?>
