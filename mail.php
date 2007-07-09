<?php 
require_once('Code/header.inc');
require_once('Code/mailtemplate.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair('index.php');
$rf = reviewForm();
$nullMailer = new Mailer(null, null);

$Conf->header("Send Mail", "mail");

$subjectPrefix = "[$Conf->shortName] ";


function contactQuery($type) {
    $contactInfo = "firstName, lastName, email, password, ContactInfo.contactId";
    $paperInfo = "Paper.paperId, Paper.title, Paper.abstract, Paper.authorInformation, Paper.outcome, Paper.blind";
    if ($type == "notsubmitted")
	return "select $contactInfo, PaperConflict.conflictType, $paperInfo from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId) where Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0 and PaperConflict.conflictType=" . CONFLICT_AUTHOR . " order by email";
    if ($type == "submitted")
	return "select $contactInfo, PaperConflict.conflictType, $paperInfo from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId) where Paper.timeSubmitted>0 and Paper.timeWithdrawn<=0 and PaperConflict.conflictType=" . CONFLICT_AUTHOR . " order by email";
    if (substr($type, 0, 14) == "author-outcome"
	&& ($out = cvtint(substr($type, 14), null)) !== null)
	return "select $contactInfo, PaperConflict.conflictType, $paperInfo from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId) where Paper.timeSubmitted>0 and Paper.outcome=$out and PaperConflict.conflictType=" . CONFLICT_AUTHOR . " order by email";
    if ($type == "review-finalized")
	return "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId) where PaperReview.reviewSubmitted>0 order by email";
    if ($type == "review-not-finalize")
	return "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId) where PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit>0 order by email";
    if ($type == "pc")
	return "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo join PCMember using (contactId)";
    return "";
}

function checkMail($send) {
    global $Conf, $subjectPrefix;
    $q = contactQuery($_REQUEST["recipients"]);
    if (!$q)
	return $Conf->errorMsg("Bad recipients value");
    $result = $Conf->qe($q, "while fetching mail recipients");
    if (!$result)
	return;
    
    $subject = defval($_REQUEST["subject"], "");
    if (substr($subject, 0, strlen($subjectPrefix)) != $subjectPrefix)
	$subject = $subjectPrefix . $subject;

    if ($send) {
	echo "<div id='foldmail' class='foldc'><div class='ellipsis'><div class='error'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong></div></div><div class='extension'><div class='confirm'>Sent mail as follows.</div>
	<table><tr><td class='caption'></td><td class='entry'><form method='post' action='mail.php' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody") as $x)
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='go' value='Prepare more mail' /></td></tr></table>
</div></div>";
    } else {
	$Conf->infoMsg("Examine the mails to check that you've gotten the result you want, then select 'Send' to send the checked mails.");
	echo "<table><tr><td class='caption'></td><td class='entry'><form method='post' action='mail.php?send=1' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody") as $x)
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='dosend' value='Send' /> &nbsp;
<input class='button' type='submit' name='cancel' value='Cancel' /></td></tr></table>\n";
    }

    $template = array($_REQUEST["subject"], $_REQUEST["emailBody"]);
    $rest = array("headers" => "Cc: $Conf->contactName <$Conf->contactEmail>");
    $last = array(0 => "", 1 => "", "to" => "");
    while (($row = edb_orow($result))) {
	$preparation = Mailer::prepareToSend($template, $row, $row, null, $rest);
	if ($preparation[0] != $last[0] || $preparation[1] != $last[1]
	    || $preparation["to"] != $last["to"]) {
	    $last = $preparation;
	    $checker = "c" . $row->contactId . "p" . $row->paperId;
	    if ($send && !defval($_REQUEST[$checker]))
		continue;
	    if ($send)
		Mailer::sendPrepared($preparation);
	    echo "<table><tr><td class='caption'>To</td><td class='entry'>";
	    if (!$send)
		echo "<input type='checkbox' name='$checker' value='1' checked='checked' /> &nbsp;";
	    echo htmlspecialchars($preparation["to"]), "</td></tr>\n";
	    echo "<td class='caption'>Subject</td><td class='entry'><tt>", htmlspecialchars($preparation[0]), "</tt></td></tr>\n";
	    echo "<td class='caption'>Body</td><td class='entry'><pre>", htmlspecialchars($preparation[1]), "</pre></td></tr>\n";
	    echo "</table>\n";
	}
    }

    if ($send)
	echo "<script>fold('mail', null);</script>";
    else {
	echo "<table><tr><td class='caption'></td><td class='entry'><form method='post' action='mail.php?send=1' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody") as $x)
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='dosend' value='Send' /> &nbsp;
<input class='button' type='submit' name='cancel' value='Cancel' /></td></tr></table>\n";
    }
    $Conf->footer();
    exit;
}

if (defval($_REQUEST["loadtmpl"])) {
    $t = defval($_REQUEST["template"], "genericmailtool");
    if ($t == "rejectnotify")
	$_REQUEST["recipients"] = "author-outcome" . min(array_keys($rf->options["outcome"]));
    else if ($t == "acceptnotify")
	$_REQUEST["recipients"] = "author-outcome" . max(array_keys($rf->options["outcome"]));
    else if ($t == "reviewremind")
	$_REQUEST["recipients"] = "review-not-finalize";
    else
	$_REQUEST["recipients"] = "submitted";
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates[$t][0]);
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates[$t][1]);
} else if (defval($_REQUEST["check"]))
    checkMail(0);
else if (defval($_REQUEST["cancel"]))
    /* do nothing */;
else if (defval($_REQUEST["send"]))
    checkMail(1);


if (!isset($_REQUEST["subject"]))
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates["genericmailtool"][0]);
if (!isset($_REQUEST["emailBody"]))
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates["genericmailtool"][1]);
if (substr($_REQUEST["subject"], 0, strlen($subjectPrefix)) == $subjectPrefix)
    $_REQUEST["subject"] = substr($_REQUEST["subject"], strlen($subjectPrefix));


echo "<form method='post' action='mail.php?check=1' enctype='multipart/form-data'>
<table class='form'>
<tr class='topspace'>
  <td class='caption'>Templates</td>
  <td class='entry'><select name='template'>";
$tmpl = array("genericmailtool" => "Generic",
	      "acceptnotify" => "Accept notification",
	      "rejectnotify" => "Reject notification",
	      "reviewremind" => "Review reminder");
if (!isset($_REQUEST["template"]) || !isset($tmpl[$_REQUEST["template"]]))
    $_REQUEST["template"] = "genericmailtool";
foreach ($tmpl as $num => $what) {
    echo "<option value='$num'";
    if ($num == $_REQUEST["template"])
	echo " selected='selected'";
    echo ">$what</option>\n";
}
echo "  </select> &nbsp;<input class='button' type='submit' name='loadtmpl' value='Load template' /><div class='smgap'></div></td>
</tr>
  <td class='caption'>Mail to</td>
  <td class='entry'><select name='recipients'>";
$recip = array("submitted" => "Contact authors of submitted papers",
	       "notsubmitted" => "Contact authors of unsubmitted papers",
	       "review-finalized" => "Reviewers who submitted at least one review",
	       "review-not-finalize" => "Reviewers with outstanding reviews",
	       "pc" => "Program committee");
foreach ($rf->options["outcome"] as $num => $what)
    if ($num)
	$recip["author-outcome$num"] = "Contact authors of $what papers";
foreach ($recip as $r => $what) {
    echo "    <option value='$r'";
    if ($r == defval($_REQUEST["recipients"]))
	echo " selected='selected'";
    echo ">", htmlspecialchars($what), "</option>\n";
}
echo "  </select></td>
</tr>

<tr>
  <td class='caption'>Subject</td>
  <td class='entry'><tt>[", htmlspecialchars($Conf->shortName), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td>
</tr>

<tr>
  <td class='caption'>Body</td>
  <td class='entry'><textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($_REQUEST["emailBody"]), "</textarea></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><input type='submit' name='send' value='Prepare mail' class='button' /><div class='smgap'></div></td>
</tr>

<tr class='last'>
  <td class='caption'></td>
  <td id='mailref' class='entry'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<p><table>
<tr><td class='plholder'><table>
<tr><td class='lcaption'><code>%URL%</code></td>
    <td class='llentry'>Site URL.</td></tr>
<tr><td class='lcaption'><code>%LOGINURL%</code></td>
    <td class='llentry'>URL for the mail's recipient to log in to the site.</td></tr>
<tr><td class='lcaption'><code>%NUMSUBMITTED%</code></td>
    <td class='llentry'>Number of papers submitted.</td></tr>
<tr><td class='lcaption'><code>%NUMACCEPTED%</code></td>
    <td class='llentry'>Number of papers accepted.</td></tr>
<tr><td class='lcaption'><code>%NAME%</code></td>
    <td class='llentry'>Full name of mail's recipient.</td></tr>
<tr><td class='lcaption'><code>%FIRST%</code>, <code>%LAST%</code></td>
    <td class='llentry'>First and last names, if any, of mail's recipient.</td></tr>
<tr><td class='lcaption'><code>%EMAIL%</code></td>
    <td class='llentry'>Email address of mail's recipient.</td></tr>
<tr><td class='lcaption'><code>%REVIEWDEADLINE%</code></td>
    <td class='llentry'>Reviewing deadline appropriate for mail's recipient.</td></tr>
</table></td><td class='plholder'><table>
<tr><td class='lcaption'><code>%NUMBER%</code></td>
    <td class='llentry'>Paper number relevant for mail.</td></tr>
<tr><td class='lcaption'><code>%TITLE%</code></td>
    <td class='llentry'>Paper title.</td></tr>
<tr><td class='lcaption'><code>%TITLEHINT%</code></td>
    <td class='llentry'>First couple words of paper title (useful for mail subject).</td></tr>
<tr><td class='lcaption'><code>%OPT(AUTHORS)%</code></td>
    <td class='llentry'>Paper authors (if recipient is allowed to see the authors).</td></tr>
<tr><td><div class='smgap'></div></td></tr>
<tr><td class='lcaption'><code>%REVIEWS%</code></td>
    <td class='llentry'>Pretty-printed paper reviews.</td></tr>
<tr><td class='lcaption'><code>%COMMENTS%</code></td>
    <td class='llentry'>Pretty-printed paper comments, if any.</td></tr>
</table></td></tr></table>
</td>

</table>
</form>\n";

$Conf->footer();


//   } else if ($who == "author-late-review") {
//       $query = "SELECT DISTINCT firstName, lastName, email, Paper.paperId, Paper.title, Paper.authorInformation, Paper.blind "
//              . "FROM ContactInfo, Paper, PaperReview, Settings "
// 	     . "WHERE Paper.timeSubmitted>0 "
// 	     . "AND PaperReview.paperId = Paper.paperId "
// 	     . "AND Paper.contactId = ContactInfo.contactId "
// 	     . "AND PaperReview.reviewSubmitted>0 "
// 	     . "AND PaperReview.reviewModified > Settings.value "
// 	     . "AND Settings.name = 'resp_open' "
// 	     . " $group_order";