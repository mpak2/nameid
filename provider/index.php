<?php
/*
    NameID, a namecoin based OpenID identity provider.
    Copyright (C) 2013 by Daniel Kraft <d@domob.eu>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/* Main page.  */

require_once ("lib/authenticator.inc.php");
require_once ("lib/html.inc.php");
require_once ("lib/messages.inc.php");
require_once ("lib/namecoind.inc.php");
require_once ("lib/openid.inc.php");
require_once ("lib/request.inc.php");
require_once ("lib/session.inc.php");

require_once ("Auth/OpenID/Discover.php");

$status = "unknown";

// Disable caching.
header("Cache-Control: no-cache");
header("Pragma: no-cache");

// Construct the basic worker classes.
$session = new Session ();
$nc = new Namecoind ();
$req = new RequestHandler ();
$openid = new OpenID ($session, $nc);
$html = new HtmlOutput ();
$msg = new MessageList ($html);

/**
 * Try handling an XRDS request.
 */
function tryXRDS ()
{
  global $req;
  global $status;
  global $serverUri;

  if ($status === "unknown" && $req->check ("xrds"))
    {
      $xrds = $req->getString ("xrds");
      switch ($xrds)
        {
        case "general":
          header ("Content-Type: application/xrds+xml");
          echo "<?xml version='1.0' encoding='utf-8' ?>\n";
?>
<xrds:XRDS
    xmlns:xrds="xri://$xrds"
    xmlns="xri://$xrd*($v*2.0)">
  <XRD>
    <Service priority="0">
      <Type><?php echo Auth_OpenID_TYPE_2_0_IDP; ?></Type>
      <URI><?php echo "$serverUri?view=openid"; ?></URI>
    </Service>
  </XRD>
</xrds:XRDS>
<?php
          $status = "xrds";
          break;

        case "identity":
          header ("Content-Type: application/xrds+xml");
          echo "<?xml version='1.0' encoding='utf-8' ?>\n";
?>
<xrds:XRDS
    xmlns:xrds="xri://$xrds"
    xmlns="xri://$xrd*($v*2.0)">
  <XRD>
    <Service priority="0">
      <Type><?php echo Auth_OpenID_TYPE_2_0; ?></Type>
      <Type><?php echo Auth_OpenID_TYPE_1_1; ?></Type>
      <URI><?php echo "$serverUri?view=openid"; ?></URI>
    </Service>
  </XRD>
</xrds:XRDS>
<?php
          $status = "xrds";
          break;
        }
    }
}

/**
 * Try to get the data for an identity page and update the
 * global state accordingly.
 */
function tryIdentityPage ()
{
  global $req, $nc;
  global $status, $identityName, $identityPage;
  global $serverUri;

  if ($status === "unknown" && $req->check ("name"))
    {
      $name = $req->getString ("name");
      $identityName = $name;
      try
        {
          $identityPage = $nc->getIdValue ($name);

          /* Also send XRDS location for the user identity page.  */
          $xrds = "$serverUri?xrds=identity&name=" . urlencode ($identityName);
          header ("X-XRDS-Location: $xrds");

          $status = "identityPage";
        }
      catch (NameNotFoundException $exc)
        {
          $status = "identityNotFound";
        }
    }
}

/**
 * Try to perform a user requested action and update the global
 * state accordingly.
 */
function tryAction ()
{
  global $req, $session, $msg, $nc, $openid;
  global $status;

  if ($status === "unknown" && $req->check ("action"))
    {
      $action = $req->getString ("action");
      switch ($action)
        {
        case "login":
          if ($req->getSubmitButton ("cancel"))
            $openid->cancel ();
          assert ($req->getSubmitButton ("login"));

          $identity = $req->getString ("identity");
          $signature = $req->getString ("signature");

          /* Redirect to loginForm in case an exception is thrown
             below (i. e., authentication fails).  */
          $status = "loginForm";

          $auth = new Authenticator ($nc, $session);
          $auth->login ($identity, $signature);

          /* No exception thrown means success.  */
          $msg->addMessage ("You have logged in successfully.");
          $status = "loggedIn";
          break;

        case "logout":
          $session->setUser (NULL);
          $msg->addMessage ("You have been logged out successfully.");
          $status = "loginForm";
          break;

        case "trust":
          if ($req->getSubmitButton ("notrust"))
            $openid->cancel ();
          assert ($req->getSubmitButton ("trust"));
          $openid->authenticate ();
          break;

        default:
          // Ignore unknown action request.
          break;
        }
    }
}

/**
 * Try to interpret a requested view.
 */
function tryView ()
{
  global $req, $session, $openid;
  global $status;

  if ($status === "unknown" && $req->check ("view"))
    {
      $view = $req->getString ("view");
      switch ($view)
        {
        case "openid":
          /* Start the OpenID authentication process.  */

          /* Handle OpenID request if there is one.  This stores it
             in the session.  */
          $openid->decodeRequest ();
          /* Fall through, since we want to login now.  */

        case "login":
          $userLoggedIn = $session->getUser ();
          if ($userLoggedIn === NULL)
            $status = "loginForm";
          else
            $status = "loggedIn";
          break;

        default:
          // Just leave status as unknown.
          break;
        }
    }
}

/**
 * Perform all page actions, possibly throwing a UIError.
 */
function performActions ()
{
  global $session;
  global $status;
  global $serverUri;

  tryXRDS ();
  tryIdentityPage ();
  tryAction ();
  tryView ();

  /* If nothing matched, show the default page and send XRDS header.  */
  if ($status === "unknown")
    {
      header ("X-XRDS-Location: $serverUri?xrds=general");
      $status = "default";
    }
}

// Now perform the action and catch errors.
$msg->runWithErrors ("performActions");

// Set some global values for the pages.
switch ($status)
  {
  case "loginForm":
    $loginNonce = $session->generateNonce ();
    break;

  case "loggedIn":
    $loggedInUser = $session->getUser ();

    /* If we have a pending request, redirect to trust page.  */
    $openidReq = $session->getRequestInfo ();
    if ($openidReq)
      {
        $status = "confirmTrust";
        $trustRoot = $openidReq->trust_root;
      }

    break;

  default:
    // Nothing to be done any more.
    break;
  }

// Clean up.  msg and html have to be kept for later.
$req->close ();
$nc->close ();
$openid->close ();
$session->close ();

// Finish off if this request was only for an XRDS file.
if ($status === "xrds")
  {
    $html->close ();
    return;
  }

/* ************************************************************************** */

// Construct page title.
switch ($status)
  {
  case "identityPage":
  case "identityNotFound":
    $pageTitle = "NameID: $identityName";
    break;

  default:
    $pageTitle = "NameID";
    break;
  }

/* Set encoding to UTF-8.  */
header ("Content-Type: text/html; charset=utf-8");

echo "<?xml version='1.0' encoding='utf-8' ?>\n";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN"
                      "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
<head>

<title><?php echo $html->escape ($pageTitle); ?></title>

<link rel="stylesheet" type="text/css" href="layout/main.css" />

</head>
<body>

<h1><?php echo $html->escape ($pageTitle); ?></h1>

<?php
$msg->finish ();
?>

<?php
$fromIndex = "yes";
include ("pages/$status.php");
?>

</body>
</html>
<?php
$html->close ();
?>