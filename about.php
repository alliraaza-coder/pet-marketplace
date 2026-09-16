<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
$page_title = 'About Us – ' . $site_settings['site_name'];
include 'includes/header.php';
?>

<style>
.about-hero { background: linear-gradient(135deg, #064e3b 0%, #065f46 50%, #047857 100%); }
.stat-card { border-radius: 1.25rem; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.07); transition: transform .3s; }
.stat-card:hover { transform: translateY(-6px); }
.team-card img { width: 100px; height: 100px; object-fit: cover; border-radius: 50%; border: 4px solid #d1fae5; }
.value-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: #ecfdf5; color: #059669; }
</style>

<!-- Hero Section -->
<div class="about-hero text-white py-5">
  <div class="container py-4 text-center">
    <i class="bi bi-feather display-2 mb-3 d-block"></i>
    <h1 class="fw-bold display-5 mb-3">About PetMarket</h1>
    <p class="lead mb-0 opacity-75">Pakistan's most trusted online marketplace for birds, pets, and accessories.</p>
  </div>
</div>

<!-- Stats -->
<div class="container py-5">
  <div class="row g-4 text-center mb-5">
    <div class="col-sm-6 col-lg-3">
      <div class="stat-card card p-4">
        <div class="display-5 fw-bold text-success mb-2">5K+</div>
        <p class="text-muted mb-0">Happy Buyers</p>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="stat-card card p-4">
        <div class="display-5 fw-bold text-success mb-2">1.2K+</div>
        <p class="text-muted mb-0">Verified Sellers</p>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="stat-card card p-4">
        <div class="display-5 fw-bold text-success mb-2">8K+</div>
        <p class="text-muted mb-0">Products Listed</p>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="stat-card card p-4">
        <div class="display-5 fw-bold text-success mb-2">50+</div>
        <p class="text-muted mb-0">Cities Covered</p>
      </div>
    </div>
  </div>

  <!-- Our Story -->
  <div class="row g-5 align-items-center mb-5">
    <div class="col-lg-6">
      <h2 class="fw-bold mb-4">Our Story</h2>
      <p class="text-muted lh-lg">PetMarket was founded with a simple mission: to connect bird and pet lovers across Pakistan in one trusted, easy-to-use platform. Whether you're looking for a hand-tamed parrot, a rare breed dog, or bird food delivered to your doorstep, we've got you covered.</p>
      <p class="text-muted lh-lg">Since our launch, we have grown to serve thousands of buyers and sellers across 50+ cities, providing a safe and transparent marketplace where every transaction is backed by our commitment to quality and trust.</p>
      <a href="shop.php" class="btn btn-success rounded-pill px-5 mt-2">Explore Products</a>
    </div>
    <div class="col-lg-6">
      <div class="row g-3">
        <?php
        $values = [
            ['bi-shield-check', 'Verified Sellers', 'Every seller goes through an identity and quality verification process.'],
            ['bi-truck', 'Safe Delivery', 'We partner with responsible couriers for safe pet delivery.'],
            ['bi-chat-heart', 'Community First', 'We foster a community of responsible pet owners and breeders.'],
            ['bi-award', 'Quality Assured', 'All listings are reviewed to ensure animal welfare standards.'],
        ];
        foreach ($values as $v): ?>
        <div class="col-sm-6">
          <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
            <div class="value-icon mb-3"><i class="bi <?= $v[0] ?>"></i></div>
            <h6 class="fw-bold"><?= $v[1] ?></h6>
            <p class="text-muted small mb-0"><?= $v[2] ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- CTA -->
  <div class="text-center py-5 bg-light rounded-4">
    <h3 class="fw-bold mb-3">Ready to join PetMarket?</h3>
    <p class="text-muted mb-4">Start buying or selling today — it only takes a minute to register.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap">
      <a href="register.php" class="btn btn-success rounded-pill px-5">Create an Account</a>
      <a href="become_seller.php" class="btn btn-outline-success rounded-pill px-5">Become a Seller</a>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
