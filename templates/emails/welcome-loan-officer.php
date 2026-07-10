<?php
/**
 * Welcome Email — New Loan Officer
 *
 * Expects (via extract()'d args from WelcomeEmail::render()):
 *
 * @var string $first_name   Recipient's first name.
 * @var string $profile_url  Live public profile URL on 21stcenturylending.com.
 * @var string $hub_url      Login URL for myhub21.com.
 * @var string $work_email   Recipient's @21stcenturylending.com email.
 *
 * @package FRSUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to 21st Century Lending</title>
</head>
<body style="margin:0; padding:0; background-color:#0b0d12; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0b0d12; padding:40px 16px;">
	<tr>
		<td align="center">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#12151c; border-radius:16px; overflow:hidden;">

				<!-- Gradient top bar -->
				<tr>
					<td style="background-color:#2563eb; background-image:linear-gradient(90deg, #2dd4da 0%, #2563eb 100%); height:6px; line-height:6px; font-size:0;">&nbsp;</td>
				</tr>

				<!-- Logo -->
				<tr>
					<td align="center" style="padding:44px 24px 8px 24px;">
						<img src="https://21stcenturylending.com/wp-content/uploads/2026/07/official-logo-hires.png" width="240" alt="21st Century Lending" style="display:block; margin:0 auto; border:0; width:240px; max-width:80%; height:auto;">
					</td>
				</tr>

				<!-- Bold headline -->
				<tr>
					<td align="center" style="padding:28px 40px 0 40px;">
						<div style="font-size:13px; letter-spacing:3px; color:#2dd4da; font-weight:bold; text-transform:uppercase; margin-bottom:10px;">You're Live</div>
						<h1 style="margin:0; font-size:32px; line-height:1.15; color:#ffffff; font-weight:800;">
							Welcome to the team,<br><?php echo esc_html( $first_name ); ?>.
						</h1>
					</td>
				</tr>

				<tr>
					<td style="padding:20px 40px 8px 40px; color:#b8bfcc;">
						<p style="margin:0; font-size:15px; line-height:1.7;">
							Your profile just went live on 21st Century Lending — clients and partners can now find your contact info, NMLS number, and application link right here:
						</p>
					</td>
				</tr>

				<!-- CTA: view profile -->
				<tr>
					<td align="center" style="padding:24px 40px 8px 40px;">
						<a href="<?php echo esc_url( $profile_url ); ?>" style="display:block; background-color:#2563eb; background-image:linear-gradient(90deg, #2dd4da 0%, #2563eb 100%); color:#ffffff; text-decoration:none; font-size:16px; font-weight:bold; padding:18px 32px; border-radius:10px;">
							View Your Live Profile &rarr;
						</a>
					</td>
				</tr>

				<tr>
					<td style="padding:36px 40px 0 40px;">
						<div style="height:1px; background-color:#242938; line-height:1px; font-size:0;">&nbsp;</div>
					</td>
				</tr>

				<!-- Editing instructions -->
				<tr>
					<td style="padding:32px 40px 4px 40px;">
						<div style="font-size:13px; letter-spacing:2px; color:#2dd4da; font-weight:bold; text-transform:uppercase; margin-bottom:10px;">Own Your Page</div>
						<h2 style="margin:0 0 14px 0; font-size:21px; color:#ffffff; font-weight:800;">Update your bio, photo & links anytime</h2>
						<p style="margin:0 0 20px 0; font-size:15px; line-height:1.7; color:#b8bfcc;">
							Everything is managed from our internal hub, <strong style="color:#ffffff;">myhub21.com</strong> — sign in with the Microsoft 365 account you already use, no new password required.
						</p>
					</td>
				</tr>

				<!-- Steps card -->
				<tr>
					<td style="padding:0 40px 8px 40px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#1a1f2b; border-left:3px solid #2dd4da; border-radius:0 10px 10px 0;">
							<tr>
								<td style="padding:22px 24px;">
									<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px; line-height:1.8; color:#dfe3ea; width:100%;">
										<tr>
											<td valign="top" width="26" style="color:#2dd4da; font-weight:800; font-size:15px;">01</td>
											<td>Go to <a href="<?php echo esc_url( $hub_url ); ?>" style="color:#2dd4da; text-decoration:none; font-weight:bold;">myhub21.com</a> and choose <strong style="color:#ffffff;">Sign in with Microsoft</strong>.</td>
										</tr>
										<tr><td colspan="2" style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
										<tr>
											<td valign="top" width="26" style="color:#2dd4da; font-weight:800; font-size:15px;">02</td>
											<td>Sign in with <strong style="color:#ffffff;"><?php echo esc_html( $work_email ); ?></strong> and your usual Microsoft 365 password.</td>
										</tr>
										<tr><td colspan="2" style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
										<tr>
											<td valign="top" width="26" style="color:#2dd4da; font-weight:800; font-size:15px;">03</td>
											<td>Open <strong style="color:#ffffff;">My Profile</strong> to update your bio, headshot, and contact info whenever you like.</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<tr>
					<td align="center" style="padding:28px 40px 44px 40px;">
						<a href="<?php echo esc_url( $hub_url ); ?>" style="display:inline-block; background-color:transparent; color:#ffffff; text-decoration:none; font-size:14px; font-weight:bold; padding:14px 30px; border-radius:10px; border:2px solid #2dd4da;">
							Log In to myhub21.com
						</a>
					</td>
				</tr>

				<!-- Footer -->
				<tr>
					<td style="background-color:#0b0d12; padding:26px 40px; text-align:center; color:#5b6373; font-size:12px; line-height:1.6;">
						21<sup>st</sup> Century Lending &middot; A division of Full Realty Services, Inc.<br>
						Questions? Reach out to your onboarding contact any time.
					</td>
				</tr>

			</table>
		</td>
	</tr>
</table>
</body>
</html>
