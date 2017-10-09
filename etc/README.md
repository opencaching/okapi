OKAPI /etc/ directory.

This directory is EXCLUDED from OCPL deployments. The deployment script
(webhooks/postcommit.py) gets rid it prior the deployment.

This directory is INCLUDED in OCDE deployments (via composer). composer
replicates the complete Okapi Git repository to vendor/opencaching/okapi,
which allows full Okapi development directly inside the OCDE code tree
([discussion](https://github.com/opencaching/okapi/pull/514)).
