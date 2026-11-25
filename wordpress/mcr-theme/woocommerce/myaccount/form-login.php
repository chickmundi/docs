<?php
/**
 * Customer sign-in and account creation (Mon compte, signed out).
 * One card: the shop's photo and the reasons to have an account on one side, the two forms on the
 * other with an animated switch. WooCommerce still handles both forms (same fields, nonces and hooks).
 *
 * @version 9.9.0 (follows woocommerce/templates/myaccount/form-login.php)
 */
defined('ABSPATH') || exit;

$en = mcr_is_en();
$can_register = 'yes' === get_option('woocommerce_enable_myaccount_registration');
$own_password = 'no' === get_option('woocommerce_registration_generate_password');
$own_username = 'no' === get_option('woocommerce_registration_generate_username');
// Open on "create" after a failed sign-up, or with #creer / #register in the address.
$start = $can_register && !empty($_POST['register']) ? 'register' : 'login'; // phpcs:ignore WordPress.Security.NonceVerification
$t = fn(string $fr, string $enx) => $en ? $enx : $fr;
$eye = '<svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-shut" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

do_action('woocommerce_before_customer_login_form');
?>
<div class="auth" data-auth data-start="<?php echo esc_attr($start); ?>">
  <aside class="auth__visual has-pbg">
    <?php echo mcrt_photo_bg('shop-spices', 'dark', true); // phpcs:ignore ?>
    <div class="auth__visual-in">
      <p class="auth__brand"><?php echo mcr_icon('store', 18); ?> <?php echo esc_html(mcr_setting('store', 'name') ?: 'Marché Chemin-du-Roy'); ?></p>
      <h2 class="auth__title" data-title-login><?php echo esc_html($t('Content de vous revoir.', 'Welcome back.')); ?></h2>
      <h2 class="auth__title" data-title-register hidden><?php echo esc_html($t('Votre marché, toujours sous la main.', 'Your market, always at hand.')); ?></h2>
      <ul class="auth__perks">
        <li style="--i:0"><?php echo mcr_icon('package-check', 20); ?><span><?php echo esc_html($t('Suivez vos commandes en direct', 'Track your orders live')); ?></span></li>
        <li style="--i:1"><?php echo mcr_icon('map-pin', 20); ?><span><?php echo esc_html($t('Adresse enregistrée : commandez en une minute', 'Saved address: order in one minute')); ?></span></li>
        <li style="--i:2"><?php echo mcr_icon('heart', 20); ?><span><?php echo esc_html($t('Retrouvez vos produits préférés', 'Find your favourite products again')); ?></span></li>
        <li style="--i:3"><?php echo mcr_icon('badge-percent', 20); ?><span><?php echo esc_html($t('Promotions et nouveaux arrivages', 'Deals and new arrivals')); ?></span></li>
      </ul>
      <div class="auth__chips" aria-hidden="true">
        <span class="auth__chip" style="--d:0s"><?php echo mcr_icon('truck', 16); ?> <?php echo esc_html($t('Livraison 7 jours sur 7', 'Delivery 7 days a week')); ?></span>
        <span class="auth__chip" style="--d:1.6s"><?php echo mcr_icon('store', 16); ?> <?php echo esc_html($t('Cueillette gratuite', 'Free pickup')); ?></span>
      </div>
    </div>
  </aside>

  <div class="auth__panel">
    <?php if ($can_register) : ?>
      <div class="auth__switch" role="tablist" aria-label="<?php echo esc_attr($t('Connexion ou création de compte', 'Sign in or create an account')); ?>">
        <span class="auth__pill" aria-hidden="true"></span>
        <button type="button" role="tab" id="tab-login" aria-controls="pane-login" aria-selected="true" data-tab="login"><?php echo esc_html($t('Se connecter', 'Sign in')); ?></button>
        <button type="button" role="tab" id="tab-register" aria-controls="pane-register" aria-selected="false" data-tab="register"><?php echo esc_html($t('Créer un compte', 'Create account')); ?></button>
      </div>
    <?php endif; ?>

    <div class="auth__track">
      <section class="auth__pane" id="pane-login" role="tabpanel" aria-labelledby="tab-login" data-pane="login">
        <h1 class="auth__h"><?php echo esc_html($t('Connexion', 'Sign in')); ?></h1>
        <p class="auth__sub"><?php echo esc_html($t('Accédez à vos commandes, adresses et favoris.', 'Get to your orders, addresses and favourites.')); ?></p>
        <form class="woocommerce-form woocommerce-form-login login auth__form" method="post" novalidate>
          <?php do_action('woocommerce_login_form_start'); ?>
          <div class="fld">
            <span class="fld__ic"><?php echo mcr_icon('mail', 18); ?></span>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text fld__in" name="username" id="username" autocomplete="username" placeholder=" " value="<?php echo !empty($_POST['username']) && empty($_POST['register']) ? esc_attr(wp_unslash($_POST['username'])) : ''; // phpcs:ignore ?>" required aria-required="true">
            <label class="fld__lb" for="username"><?php echo esc_html($t('Courriel ou identifiant', 'Email or username')); ?></label>
          </div>
          <div class="fld">
            <span class="fld__ic"><?php echo mcr_icon('lock', 18); ?></span>
            <input class="woocommerce-Input woocommerce-Input--text input-text fld__in" type="password" name="password" id="password" autocomplete="current-password" placeholder=" " required aria-required="true">
            <label class="fld__lb" for="password"><?php echo esc_html($t('Mot de passe', 'Password')); ?></label>
            <button type="button" class="fld__eye" data-eye aria-label="<?php echo esc_attr($t('Afficher le mot de passe', 'Show password')); ?>" aria-pressed="false"><?php echo $eye; // phpcs:ignore ?></button>
          </div>
          <?php do_action('woocommerce_login_form'); ?>
          <div class="auth__row">
            <label class="auth__check"><input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" checked> <span><?php echo esc_html($t('Rester connecté', 'Keep me signed in')); ?></span></label>
            <a class="auth__link" href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php echo esc_html($t('Mot de passe oublié ?', 'Forgot password?')); ?></a>
          </div>
          <?php wp_nonce_field('woocommerce-login', 'woocommerce-login-nonce'); ?>
          <button type="submit" class="auth__btn woocommerce-button button woocommerce-form-login__submit" name="login" value="<?php esc_attr_e('Log in', 'woocommerce'); ?>"><span><?php echo esc_html($t('Se connecter', 'Sign in')); ?></span><?php echo mcr_icon('arrow-right', 18); ?></button>
          <?php do_action('woocommerce_login_form_end'); ?>
        </form>
        <?php if ($can_register) : ?>
          <p class="auth__alt"><?php echo esc_html($t('Pas encore de compte ?', 'No account yet?')); ?> <button type="button" class="auth__link" data-tab="register"><?php echo esc_html($t('Créez-le en 30 secondes', 'Create one in 30 seconds')); ?></button></p>
        <?php endif; ?>
      </section>

      <?php if ($can_register) : ?>
        <section class="auth__pane" id="pane-register" role="tabpanel" aria-labelledby="tab-register" data-pane="register" hidden>
          <h1 class="auth__h"><?php echo esc_html($t('Créer un compte', 'Create an account')); ?></h1>
          <p class="auth__sub"><?php echo esc_html($t('Gratuit, et vos prochaines commandes iront plus vite.', 'Free, and your next orders will be faster.')); ?></p>
          <form method="post" class="woocommerce-form woocommerce-form-register register auth__form" <?php do_action('woocommerce_register_form_tag'); ?> novalidate>
            <?php do_action('woocommerce_register_form_start'); ?>
            <div class="fld">
              <span class="fld__ic"><?php echo mcr_icon('user', 18); ?></span>
              <input type="text" class="woocommerce-Input woocommerce-Input--text input-text fld__in" name="mcr_first_name" id="reg_first_name" autocomplete="given-name" placeholder=" " value="<?php echo !empty($_POST['mcr_first_name']) ? esc_attr(wp_unslash($_POST['mcr_first_name'])) : ''; // phpcs:ignore ?>">
              <label class="fld__lb" for="reg_first_name"><?php echo esc_html($t('Prénom', 'First name')); ?></label>
            </div>
            <?php if ($own_username) : ?>
              <div class="fld">
                <span class="fld__ic"><?php echo mcr_icon('user', 18); ?></span>
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text fld__in" name="username" id="reg_username" autocomplete="username" placeholder=" " value="<?php echo !empty($_POST['username']) ? esc_attr(wp_unslash($_POST['username'])) : ''; // phpcs:ignore ?>" required aria-required="true">
                <label class="fld__lb" for="reg_username"><?php echo esc_html($t('Identifiant', 'Username')); ?></label>
              </div>
            <?php endif; ?>
            <div class="fld">
              <span class="fld__ic"><?php echo mcr_icon('mail', 18); ?></span>
              <input type="email" class="woocommerce-Input woocommerce-Input--text input-text fld__in" name="email" id="reg_email" autocomplete="email" placeholder=" " value="<?php echo !empty($_POST['email']) ? esc_attr(wp_unslash($_POST['email'])) : ''; // phpcs:ignore ?>" required aria-required="true">
              <label class="fld__lb" for="reg_email"><?php echo esc_html($t('Courriel', 'Email')); ?></label>
            </div>
            <?php if ($own_password) : ?>
              <div class="fld">
                <span class="fld__ic"><?php echo mcr_icon('lock', 18); ?></span>
                <input type="password" class="woocommerce-Input woocommerce-Input--text input-text fld__in" name="password" id="reg_password" autocomplete="new-password" placeholder=" " required aria-required="true">
                <label class="fld__lb" for="reg_password"><?php echo esc_html($t('Choisissez un mot de passe', 'Choose a password')); ?></label>
                <button type="button" class="fld__eye" data-eye aria-label="<?php echo esc_attr($t('Afficher le mot de passe', 'Show password')); ?>" aria-pressed="false"><?php echo $eye; // phpcs:ignore ?></button>
              </div>
              <div class="auth__meter" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
              <p class="auth__hint" data-meter-hint><?php echo esc_html($t('Au moins 8 caractères, avec des lettres et des chiffres.', 'At least 8 characters, with letters and numbers.')); ?></p>
            <?php else : ?>
              <p class="auth__hint"><?php echo esc_html($t('Un lien pour choisir votre mot de passe vous sera envoyé par courriel.', 'A link to set your password will be sent to your email.')); ?></p>
            <?php endif; ?>
            <?php do_action('woocommerce_register_form'); ?>
            <?php wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce'); ?>
            <button type="submit" class="auth__btn woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="register" value="<?php esc_attr_e('Register', 'woocommerce'); ?>"><span><?php echo esc_html($t('Créer mon compte', 'Create my account')); ?></span><?php echo mcr_icon('arrow-right', 18); ?></button>
            <?php do_action('woocommerce_register_form_end'); ?>
          </form>
          <p class="auth__alt"><?php echo esc_html($t('Déjà client ?', 'Already have an account?')); ?> <button type="button" class="auth__link" data-tab="login"><?php echo esc_html($t('Connectez-vous', 'Sign in')); ?></button></p>
        </section>
      <?php endif; ?>
    </div>

    <p class="auth__guest"><?php echo mcr_icon('shield-check', 16); ?> <?php echo esc_html($t('Pas obligé : vous pouvez aussi commander sans compte.', 'Optional: you can also order without an account.')); ?></p>
  </div>
</div>
<?php do_action('woocommerce_after_customer_login_form'); ?>
