Symfony Standard Edition Skeleton
========================

Welcome to the Symfony Standard Edition, with all the Acme junk, routes and security configuration removed.
Perfect for cloning in an environment, updating with Composer and getting started on a new project.

Getting started with this Symfony Skeleton:
-------------------------------

#### Download the source, clean up references to this git repository
````
git clone git@github.com:andrewthecoder/symfony-skeleton.git
cd symfony-skeleton
find . | grep .git | xargs rm -rf
````

#### Install Composer, install/update Symfony
````
curl -sS https://getcomposer.org/installer | php
php composer.phar install
````

#### Fix app/logs and app/cache permissions

On linux web servers with sudo and setfacl, this means:
````
rm -rf app/cache/*
rm -rf app/logs/*
APACHEUSER=`ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data' | grep -v root | head -1 | cut -d\  -f1`
sudo setfacl -R -m u:"$APACHEUSER":rwX -m u:`whoami`:rwX app/cache app/logs
sudo setfacl -dR -m u:"$APACHEUSER":rwX -m u:`whoami`:rwX app/cache app/logs
````

On other linux systems with setfacl, find out what the apache user is and put it in the APACHEUSER var:
````
rm -rf app/cache/*
rm -rf app/logs/*
APACHEUSER=
setfacl -R -m u:"$APACHEUSER":rwX -m u:`whoami`:rwX app/cache app/logs
setfacl -dR -m u:"$APACHEUSER":rwX -m u:`whoami`:rwX app/cache app/logs
````

If neither of these work (no setfacl, for example), there is a umask workaround [here](http://symfony.com/doc/current/book/installation.html).

#### Create your app bundle
````
php app/console generate:bundle --namespace={VENDOR NAME}/Bundle/{BUNDLE NAME}Bundle --format=yml
````

http://symfony.com/doc/current/bundles/SensioGeneratorBundle/commands/generate_bundle.html

#### Create database, generate entities and update database schema
````
php app/console doctrine:database:create
php app/console doctrine:generate:entity
php app/console doctrine:schema:update --force
````

http://symfony.com/doc/current/book/doctrine.html

#### Initialise your new Github repository with this code
````
git init
git add .
git commit -m 'initial commit, including symfony2 skeleton'
git remote add origin git@github.com:{USERNAME}/{REPOSITORY}
git push origin master
````

http://symfony.com/doc/current/cookbook/workflow/new_project_git.html
https://help.github.com/articles/create-a-repo

#### Create pages

http://symfony.com/doc/current/book/page_creation.html

