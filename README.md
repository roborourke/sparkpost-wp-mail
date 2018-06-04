# SparkPost WP Mail Dropin

Dropin wp_mail() replacement for WordPress that uses the SparkPost.com 
API.

Heavily based on prior art by 
[@danielbachhuber](https://github.com/danielbachhuber) for 
[Mandrill](https://github.com/danielbachhuber/mandrill-wp-mail)

## How to Use

To implement this drop-in, add it to your `mu-plugins` directory. In 
order for it to work, you must define your SparkPost API key. The easiest 
way to do this is to add it to your `wp-config.php` file like so:

`define( 'SPARKPOST_API_KEY', 'your-api-key' );`

If you've cloned this repo into your mu-plugins directory as the full 
folder (e.g. `wp-content/mu-plugins/sparkpost-wp-mail`, you'll need to 
make sure you load the plugin files:

```
<?php

require_once dirname( __FILE__ ) . '/sparkpost-wp-mail/sparkpost-wp-mail.php';
```

## A Note About SparkPost
 
Sparkpost require sending domain verification via TXT records in your
DNS or receiving an email at your domain.

For local development you can use their sandbox which allows up to 50 
emails.

```php
<?php

add_filter( 'wp_mail_from', function() {
    return 'wordpress@sparkpostbox.com';
} );
```

## A Note About Composer

This plugin can be installed and managed using Composer; however, 
because of the way Composer and mu-plugins work, you'll need a 
[bit of a workaround](https://gist.github.com/richardtape/05c70849e949a5017147) 
to make sure the plugin is loaded. For more information about how to use 
this drop-in with Composer, read this 
[blog post](https://richardtape.com/2014/08/22/composer-and-wordpress-mu-plugins/) 
by [Richard Tape](https://github.com/richardtape).
