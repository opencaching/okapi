# The OKAPI Project #

Quick download link: [latest version](https://github.com/opencaching/okapi/archive/master.zip)


## Why are you here? ##

If you are an external developer and you want to **USE** the API (not implement
it), you should rather [be reading this](https://opencaching.pl/okapi/).
The description below is primarily for OC administrators _AND_ people who want
to help implement OKAPI backend.


## What is OKAPI? ##

**OKAPI** is a plugin for National **Opencaching.XX** sites.

  * It provides your site with a set of useful RESTful API methods,
  * Allows external developers to easily access **public** Opencaching data,
  * Allows access to **private** (user-related) data through **OAuth** 3-legged
    authorization.
  * Sends **email notifications** to site admins in case something goes wrong.

See a live installation here: https://opencaching.pl/okapi/


### Who's using OKAPI? ###

OKAPI Project started in August 2011 and has become the standard API for
national Opencaching sites. Currently it is being used by the
following sites:

  * https://www.opencaching.pl/okapi/
  * https://www.opencaching.de/okapi/ (+.it +.fr)
  * http://www.opencaching.us/okapi/
  * http://www.opencaching.nl/okapi/
  * http://www.opencaching.ro/okapi/
  * https://www.opencache.uk/okapi/

There is one site where OKAPI is not available yet:

  * http://www.opencaching.cz/

The API itself is also being used by numerous geocaching clients (e.g. c:geo).


### Who is developing OKAPI? ###

Like the rest of Opencaching, OKAPI is an open project. Every developer is
welcome to submit their pull requests. If you are an application developer and
you need a method which does not yet exist, maybe you'd care enough to write
one? ;)

If you want to develop a new OKAPI method, you are welcome to start a new Issue
for your work. Let others know what you're doing! Issue page is a great place
for a discussion too.

Also, check [our coding style guide](etc/CODESTYLE.md).


## Terms of data use ##

Current version of OKAPI comes with a document describing _Terms of Use for
Opencaching data_ (like [this one](https://opencaching.pl/okapi/signup.html)).
This document is about the OC data and **IS NOT** necessarily meant to stay the
same across different OKAPI installations. Every OC installation may have its
own version of this document. Contact us if you need one.


## Can I change OKAPI locally? ##

Local, **uncommited** modifications are **forbidden**. You should **commit**
all your modifications.

This doesn't mean that you cannot change anything - you are free to have parts
of your own implementation of OKAPI interface or your own content of "terms
and conditions" document. **BUT**, you have to commit all these changes to the
**official OKAPI repository** (not YOUR repository). This means that, for
example, OKAPI code **SHOULD** contain different versions of one SQL query
**IF** tables in OC databases turn out to be incompatible. You have to think of
all other installations when you commit anything. Once you commit to the
`master` branch, new public package will be automatically generated for other
OC servers to download.

In other words, if you want to change something, you have to do this in such
way, that the same change will also work on all other OC sites:

  * Every OKAPI developer will TRY to develop new methods in such a way, that
    they will instantly work on all other OC sites.
  * If the developer cannot do such a thing, he will contact other developers
    to help him.
  * If the thing the developer is trying to achieve is **impossible** to
    achieve in other OC installations, then he MAY add a method/field which
    works only on his own installation, but he HAS TO include the public
    documentation for this method which will say "this method works only on
    some OC installations, if it doesn't work, it will return null" (or
    something similar).
  * We (the current developers) WILL also act according to these rules. Once
    you have OKAPI installed, we will make every effort for future versions of
    OKAPIs to be compatible with your site.

You should know that there are two primary branches of Opencaching - we call
them OCPL and OCDE - and all OKAPI methods MUST support both these branches
(unless the particular functionality is available on only one of them). You can
read more about OCPL and OCDE branching
[here](https://github.com/opencaching/opencaching-pl/wiki#brief-introduction-in-english).
You can also read about how OKAPI tries to deal with OC site and branch differences
[here](https://opencaching.pl/okapi/introduction.html#oc-site-differences).


## Requirements ##

  * PHP 5.6
  * A working Opencaching site.


## Installation / Update Instructions ##

An OKAPI installation is bundled with both active Opencaching code distributions,
namely the OCDE code branch (that you can obtain from the
[OCDE repository](https://github.com/OpencachingDeutschland/oc-server3/tree/stable))
and the OCPL code branch (available in the
[OCPL repository](https://github.com/opencaching/opencaching-pl)). The OCDE
installation will fetch current OKAPI code via Composer, while it is directly
included in the OCPL distribution.

After setting up the OC installation, take these steps to enable OKAPI:

  1. Verify the settings that are passed to OKAPI through the
     `<rootpath>/okapi_settings.php` file. They are configured in the
     `settings.inc.php` file, which resides in the `<rootpath>/lib`
     directory for an OCPL installation, and in the `<rootpath>/config2`
     directory for an OCDE installation.
  2. Make sure Apache allows OKAPI's `.htaccess` to override stuff. On some
     servers you don't need to do anything. On others, you need to add
     something like this to your Apache config:

```apache
<Directory /path/to/okapi>
  AllowOverride All
  php_admin_value safe_mode "off"
</Directory>
```

  3. Update OKAPI database (visit `http(s)://yoursite/okapi/update`),
  4. Check your email. OKAPI should send you email messages with further
     installation steps like installation the OKAPI cronjob.

**Important:** Some OC installations use automatic updates via a post-commit
script. This means that every change commited to OKAPI will be immediatelly
installed on production servers (after about 30 seconds of delay). If you'd
like your installation to be updated in a similar manner, email us.

### Additional development settings ###

If you want your IDE to properly match OKAPI's absolute paths used in OKAPI's
require_once statements, then add your OC site's `<rootpath>` to your IDE's
include path.


### Reverting to a previous revision ###

Scenario:

  * You've updated your OKAPI installation (from revision X to Y).
  * OKAPI started sending you notification email about errors.

Possible reasons:

  * You forgot to run `http(s)://yoursite/okapi/update` script.
  * Missing a table/field in database (which wasn't required in X, but is
    required in Y).
  * Missing PHP module (which wasn't required in X, but is required in Y).
  * etc.

Quick fix:

  * Re-install the previous version of OKAPI (rev. X). Contact OKAPI developers
    for support.


## Why a separate repository? ##

You may wonder why OKAPI is not distributed along the rest of the Opencaching
website code. Currently there is no ONE repository for all Opencaching sites.
Each Opencaching site is a bit different, though all share a similar database
structure.

Unlike the Opencaching front-end pages, it is important for all OKAPI instances
to stay the same. In order for the external developers to use these services,
they **must** be compatible with one another.
