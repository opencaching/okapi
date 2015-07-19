![http://opencaching-api.googlecode.com/svn/trunk/etc/okapi-googlecode-banner.jpg](http://opencaching-api.googlecode.com/svn/trunk/etc/okapi-googlecode-banner.jpg)

# The OKAPI Project - API for Opencaching sites #

Quick download link: http://rygielski.net/r/okapi-latest

## Why are you here? ##

  * **If you are an external developer and you want to `*`USE`*` the API (not implement it), you should rather [be reading this](http://opencaching.pl/okapi/).**
  * **The description below is primarily for OC administrators _AND_ people who want to help implement OKAPI backend.**

## What is OKAPI? ##

**OKAPI** is a plugin for National **Opencaching.XX** sites (also known as _Opencaching Network_ nodes).

  * It provides your site with a set of useful RESTful API methods,
  * Allows external developers to easily access **public** Opencaching data,
  * Allows access to **private** (user-related) data through **OAuth** 3-legged authorization.
  * Sends **email notifications** to site admins in case something goes wrong.

See a working version here: http://opencaching.pl/okapi/

Do not confuse Opencaching national sites with opencaching.com, which is a completly different project.

### Who's using OKAPI? ###

OKAPI Project started in August 2011. Currently it is being used by the following Opencaching sites:

  * http://www.opencaching.pl/okapi/
  * http://www.opencaching.de/okapi/ (+.it +.es)
  * http://www.opencaching.org.uk/okapi/
  * http://www.opencaching.us/okapi/
  * http://www.opencaching.nl/okapi/

The project is aiming to become a standard API for all National Opencaching.XX sites.

### Who CAN use OKAPI? ###

We believe this plugin is capable of working with most of the following National Opencaching sites:

  * http://www.opencaching.pl/ (DEPLOYED)
  * http://www.opencaching.se/
  * http://www.opencaching.us/ (DEPLOYED)
  * http://www.opencaching.org.uk/ (DEPLOYED)
  * http://www.opencaching.de/ (DEPLOYED)
  * http://www.opencaching.cz/
  * http://www.opencaching.no/
  * http://www.opencaching.lv/
  * http://www.opencaching.it/ (DEPLOYED)
  * http://www.opencachingspain.es/ (DEPLOYED)
  * http://www.opencaching.nl/ (DEPLOYED)

### Who is developing OKAPI? ###

Like the rest of Opencaching, OKAPI is an open project. Every developer is welcome to submit their patches. If you are an application developer and you need a method which does not yet exist, maybe you'd care enough to create one?

If you want to develop a new OKAPI method, you are welcome to start a new Issue for your work. Let others know what you're doing! Issue page is a great place for a discussion too.

## Terms of data use ##

Current version of OKAPI comes with a document describing _Terms of Use for Opencaching data_ (like [this one](http://opencaching.pl/okapi/signup.html)). This document is about the OC data and **IS NOT** necessarily meant to stay the same across different OKAPI installations. Every OC installation may have its own version of this document.

## Can I change OKAPI locally? ##

Local, **uncommited** modifications are **forbidden**. You should **commit** all your modifications.

This doesn't mean that you cannot change anything - you are free to have parts of your own implementation of OKAPI interface or your own content of "terms and conditions" document. **BUT**, you have to commit all these changes to **common OKAPI repository** (not YOUR repository). This means that, for example, OKAPI code **SHOULD** contain different versions of one SQL query **IF** tables in OC databases turn out to be incompatible. You have to think of all other installations when you commit anything. Once you commit to the trunk, new public package will be automatically generated for other OC servers to download.

In other words, if you want to change something, you have to do this in such way, that the same change will also work on all other OC nodes:
  * Every OKAPI developer will TRY to develop new methods in such a way, that they will instantly work on all other OC sites.
  * If the developer cannot do such a thing, he will contact other developers to help him.
  * If the thing the developer is trying to achieve is **impossible** to achieve in other OC installations, then he MAY add a method/field which works only on his own installation, but he HAS TO include the public documentation for this method which will say "this method works only on some OC installations, if it doesn't work, it will return null" (or something similar).
  * We (the current developers) WILL also act according to these rules. Once you have OKAPI installed, we will make every effort for future versions of OKAPIs to be compatible with your site.

## Requirements ##

  * PHP 5.3

## Installation / Update Instructions ##

  1. Fetch the latest deployment package here: http://rygielski.net/r/okapi-latest
  1. Make sure you have a working Opencaching installation. OKAPI is not a standalone application, it is a plugin. More information: https://code.google.com/p/opencaching-api/issues/detail?id=299
  1. Patch your installation with OKAPI code. To put it plainly, **just copy and replace the files**. If you're using SVN/GIT, then you will probably also want to view and commit the changes to your local repository.
  1. Create **`<rootpath>/okapi_settings.php`** file. [See an example here](http://code.google.com/p/opencaching-pl/source/browse/trunk/okapi_settings.php) (from OCPL). See `okapi/settings.php` for the full list of available settings.
  1. Make sure Apache allows OKAPI's .htaccess to override stuff. On some servers you don't need to do anything. On others, you need to add something like this to your Apache config:
```
<Directory /path/to/okapi>
  AllowOverride All
  php_value short_open_tag 1
  php_admin_value safe_mode "off"
</Directory>
```
  1. Update OKAPI database (call http://yoursite/okapi/update),
  1. Check your email. OKAPI should send you email messages with further installation steps.

**Important:** Some OC installations use automatic updates via a post-commit script. This means that every change commited to OKAPI will be immediatelly installed on production servers (after about 30 seconds of delay). If you'd like your installation to be updated the same way, email us.

### Reverting to a previous revision ###

Scenario:
  * You've updated your OKAPI installation (from revision X to Y).
  * OKAPI started sending you notification email about errors.
Possible reasons:
  * You forgot to run http://yoursite/okapi/update script.
  * Missing a table/field in database (which wasn't required in X, but is required in Y).
  * Missing PHP module (which wasn't required in X, but is required in Y).
  * etc.
Quick fix:
  * Re-install the previous version of OKAPI (rev. X). Contact OKAPI developers for support.

## Why a separate repository? ##

You may wonder why OKAPI is not distributed along the rest of the Opencaching code. Currently there is no ONE repository for all Opencaching sites. Each Opencaching site is a bit different, though all share a similar database structure.

Unlike the Opencaching front-end pages, it is important for all OKAPI instances to stay the same. In order for the external developers to use these services, they **must** be compatible with one another.
