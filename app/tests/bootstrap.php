<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Les tests SUPPRIMENT et recréent le schéma : ils ne doivent jamais atteindre une vraie base. `phpunit.dist.xml` ne force que
// `$_SERVER`, or une variable réelle (celle du conteneur de développement) arrive aussi dans `$_ENV`, que Symfony lit en
// premier. On l'impose donc partout, avant tout chargement de `.env` et tout démarrage du noyau.
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
putenv('DATABASE_URL=sqlite:///:memory:');

if (method_exists(Dotenv::class, 'bootEnv')) {
    new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
