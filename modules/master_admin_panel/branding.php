<?php include('includes/header.php');

$brandingFile = 'data/branding_settings.json';
$branding = file_exists($brandingFile) ? json_decode(file_get_contents($brandingFile), true) : [
  'default_logo' => '',
  'fallback_email_reply_to' => 'support@corefluxapp.com',
  'global_footer_message' => 'Sent via CoreFlux'
];
?>

<div class="admin-container">
  <h2>Branding & Email Identity</h2>

  <form action="save_branding.php" method="POST" enctype="multipart/form-data" class="form-grid">

    <label>Default Tenant Logo:</label>
    <input type="file" name="logo_file" accept="image/png, image/jpeg">
    <?php if (!empty($branding['default_logo'])): ?>
      <img src="<?= $branding['default_logo'] ?>" height="60" alt="Default Logo" />
    <?php endif; ?>

    <label>Fallback Reply-To Email:</label>
    <input type="email" name="fallback_email_reply_to" value="<?= $branding['fallback_email_reply_to'] ?>" required>

    <label>Global Email Footer Message:</label>
    <textarea name="global_footer_message"><?= htmlspecialchars($branding['global_footer_message']) ?></textarea>

    <button type="submit" class="btn-save">Save Branding</button>
  </form>
</div>

<?php include('includes/footer.php'); ?>
