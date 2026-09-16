<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = sanitize_input($_POST['name'] ?? '');
    $email   = sanitize_input($_POST['email'] ?? '');
    $subject = sanitize_input($_POST['subject'] ?? '');
    $message = sanitize_input($_POST['message'] ?? '');

    if ($name && $email && $subject && $message) {
        // In a real app you'd send an email or save to DB
        $success_msg = 'Thank you, ' . $name . '! Your message has been received. We will get back to you within 24 hours.';
    } else {
        $error_msg = 'Please fill in all required fields.';
    }
}

$page_title = 'Contact Us – ' . $site_settings['site_name'];
include 'includes/header.php';
?>

<style>
.contact-hero { background: linear-gradient(135deg, #064e3b 0%, #047857 100%); }
.contact-card { border-radius: 1.25rem; border: none; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
.info-item i { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: #ecfdf5; color: #059669; flex-shrink: 0; }
</style>

<!-- Hero -->
<div class="contact-hero text-white py-5">
  <div class="container py-3 text-center">
    <i class="bi bi-envelope-heart display-3 mb-3 d-block"></i>
    <h1 class="fw-bold">Contact Us</h1>
    <p class="lead opacity-75">We'd love to hear from you. Send us a message!</p>
  </div>
</div>

<div class="container py-5">
  <div class="row g-5">

    <!-- Contact Info -->
    <div class="col-lg-4">
      <h4 class="fw-bold mb-4">Get in Touch</h4>
      <div class="d-flex align-items-start gap-3 mb-4">
        <div class="info-item"><i class="bi bi-envelope"></i></div>
        <div>
          <div class="fw-semibold">Email</div>
          <div class="text-muted"><?= $site_settings['support_email'] ?></div>
        </div>
      </div>
      <div class="d-flex align-items-start gap-3 mb-4">
        <div class="info-item"><i class="bi bi-telephone"></i></div>
        <div>
          <div class="fw-semibold">Phone</div>
          <div class="text-muted"><?= $site_settings['phone'] ?></div>
        </div>
      </div>
      <div class="d-flex align-items-start gap-3 mb-4">
        <div class="info-item"><i class="bi bi-geo-alt"></i></div>
        <div>
          <div class="fw-semibold">Location</div>
          <div class="text-muted">Lahore, Punjab, Pakistan</div>
        </div>
      </div>
      <div class="d-flex align-items-start gap-3 mb-4">
        <div class="info-item"><i class="bi bi-clock"></i></div>
        <div>
          <div class="fw-semibold">Business Hours</div>
          <div class="text-muted">Mon – Sat: 9am – 6pm</div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <a href="#" class="btn btn-outline-success rounded-circle" style="width:42px;height:42px;"><i class="bi bi-facebook"></i></a>
        <a href="#" class="btn btn-outline-success rounded-circle" style="width:42px;height:42px;"><i class="bi bi-instagram"></i></a>
        <a href="#" class="btn btn-outline-success rounded-circle" style="width:42px;height:42px;"><i class="bi bi-whatsapp"></i></a>
        <a href="#" class="btn btn-outline-success rounded-circle" style="width:42px;height:42px;"><i class="bi bi-youtube"></i></a>
      </div>
    </div>

    <!-- Contact Form -->
    <div class="col-lg-8">
      <div class="contact-card card p-4 p-lg-5">
        <h4 class="fw-bold mb-4">Send a Message</h4>

        <?php if ($success_msg): ?>
        <div class="alert alert-success rounded-3"><i class="bi bi-check-circle-fill me-2"></i><?= $success_msg ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error_msg ?></div>
        <?php endif; ?>

        <form method="POST" action="contact.php" novalidate>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control rounded-3" placeholder="Your name" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control rounded-3" placeholder="your@email.com" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Subject <span class="text-danger">*</span></label>
              <input type="text" name="subject" class="form-control rounded-3" placeholder="How can we help?" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Message <span class="text-danger">*</span></label>
              <textarea name="message" class="form-control rounded-3" rows="6" placeholder="Tell us more..." required></textarea>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold">
                <i class="bi bi-send me-2"></i>Send Message
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php include 'includes/footer.php'; ?>
