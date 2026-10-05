<?php
/**
 * Branded email wrapper for plain-text mail, used only when the wp-email-template plugin is not
 * active (see blueline_email_template_plugin_active(), includes/mail/mail.php).
 *
 * Every style is inline, not in a <style> block: this is the fallback path, so it assumes
 * nothing beyond a plain <table> layout, including clients that strip <style> blocks entirely.
 *
 * $message is plain text by contract and may originate from a public form submission (Contact
 * Form 7, Gravity Forms): esc_html() before nl2br() below, never trusted as markup.
 *
 * @var string $subject             The email's own subject line.
 * @var string $message             The plain-text message body.
 * @var array  $blueline_core_brand Brand colours and logo, from blueline_core_email_brand().
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

$blueline_logo_url  = $blueline_core_brand['logo'];
$blueline_site_name = get_bloginfo( 'name' );
$blueline_site_url  = home_url( '/' );
?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title><?php echo esc_html( $blueline_site_name ); ?></title>
</head>
<body style="margin:0; padding:0; background-color:<?php echo esc_attr( $blueline_core_brand['paper'] ); ?>;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:<?php echo esc_attr( $blueline_core_brand['paper'] ); ?>;">
		<tr>
			<td align="center" style="padding:24px 16px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:<?php echo esc_attr( $blueline_core_brand['white'] ); ?>;">
					<?php if ( '' !== $blueline_logo_url ) : ?>
					<tr>
						<td align="center" style="padding:24px; background-color:<?php echo esc_attr( $blueline_core_brand['paper'] ); ?>;">
							<a href="<?php echo esc_url( $blueline_site_url ); ?>" style="text-decoration:none;">
								<img src="<?php echo esc_url( $blueline_logo_url ); ?>" alt="<?php echo esc_attr( $blueline_site_name ); ?>" style="max-width:240px; height:auto; border:0;" />
							</a>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:24px 24px 0; font-family:Helvetica, Arial, sans-serif; font-size:18px; font-weight:bold; color:<?php echo esc_attr( $blueline_core_brand['ink'] ); ?>;">
							<?php echo esc_html( $subject ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding:16px 24px 24px; font-family:Helvetica, Arial, sans-serif; font-size:14px; line-height:1.5; color:<?php echo esc_attr( $blueline_core_brand['ink'] ); ?>;">
							<?php echo nl2br( esc_html( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wraps already-esc_html()'d text; nl2br()'s own <br> tags are static, trusted markup, not user input. ?>
						</td>
					</tr>
					<tr>
						<td align="center" style="padding:16px 24px; background-color:<?php echo esc_attr( $blueline_core_brand['white'] ); ?>; border-top:1px solid <?php echo esc_attr( $blueline_core_brand['border'] ); ?>; font-family:Helvetica, Arial, sans-serif; font-size:11px; color:<?php echo esc_attr( $blueline_core_brand['ink_mid'] ); ?>;">
							<?php echo esc_html( $blueline_site_name ); ?> &ndash; <a href="<?php echo esc_url( $blueline_site_url ); ?>" style="color:<?php echo esc_attr( $blueline_core_brand['accent_text'] ); ?>;"><?php echo esc_html( $blueline_site_url ); ?></a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
