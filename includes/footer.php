<!-- Footer -->
<footer class="main-footer">
    <div class="container">
        <div class="row">
            <!-- Company Info -->
            <div class="col-lg-4 col-md-6 mb-5 footer-widget">
                <a href="<?php echo BASE_URL; ?>/index.php" class="d-flex align-items-center text-decoration-none mb-4">
                    <i class="bi bi-feather fs-2 text-success me-2"></i>
                    <h3 class="mb-0 fw-bold text-white"><?php echo $site_settings['site_name']; ?></h3>
                </a>
                <p class="text-muted mb-4 pe-lg-4">Your premium destination for buying and selling beautiful birds, lovely pets, high-quality accessories, and nutritious foods. A trusted marketplace for all pet lovers.</p>
                <div class="d-flex align-items-center text-muted mb-2">
                    <i class="bi bi-geo-alt text-success me-3 fs-5"></i>
                    <span>123 Pet Street, Animal City, PC 12345</span>
                </div>
                <div class="d-flex align-items-center text-muted mb-2">
                    <i class="bi bi-telephone text-success me-3 fs-5"></i>
                    <span><?php echo $site_settings['phone']; ?></span>
                </div>
                <div class="d-flex align-items-center text-muted">
                    <i class="bi bi-envelope text-success me-3 fs-5"></i>
                    <span><?php echo $site_settings['support_email']; ?></span>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="col-lg-2 col-md-6 mb-5 footer-widget">
                <h4>Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="<?php echo BASE_URL; ?>/about.php">About Us</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/shop.php">Shop Products</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/market.php">Bird Market</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/blog.php">Our Blog</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/contact.php">Contact Us</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/faq.php">FAQs</a></li>
                </ul>
            </div>

            <!-- Categories -->
            <div class="col-lg-2 col-md-6 mb-5 footer-widget">
                <h4>Categories</h4>
                <ul class="footer-links">
                    <li><a href="#">Parrots & Birds</a></li>
                    <li><a href="#">Cats & Dogs</a></li>
                    <li><a href="#">Small Pets</a></li>
                    <li><a href="#">Bird Foods</a></li>
                    <li><a href="#">Pet Accessories</a></li>
                    <li><a href="#">Cages & Houses</a></li>
                </ul>
            </div>

            <!-- Newsletter -->
            <div class="col-lg-4 col-md-6 mb-5 footer-widget">
                <h4>Newsletter</h4>
                <p class="text-muted mb-4">Subscribe to our newsletter and get 10% off your first purchase, plus weekly pet care tips!</p>
                <form action="#" method="POST" class="d-flex mb-4">
                    <input type="email" class="form-control bg-dark border-secondary text-white rounded-start-2 py-2" placeholder="Your Email Address" required>
                    <button type="submit" class="btn btn-success rounded-end-2 px-3"><i class="bi bi-send"></i></button>
                </form>
                <div class="social-links">
                    <a href="#"><i class="bi bi-facebook"></i></a>
                    <a href="#"><i class="bi bi-twitter"></i></a>
                    <a href="#"><i class="bi bi-instagram"></i></a>
                    <a href="#"><i class="bi bi-youtube"></i></a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer Bottom -->
    <div class="footer-bottom">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start mb-3 mb-md-0 text-muted">
                    &copy; <?php echo date('Y'); ?> <?php echo $site_settings['site_name']; ?>. All rights reserved.
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <a href="<?php echo BASE_URL; ?>/privacy.php" class="text-muted text-decoration-none me-3">Privacy Policy</a>
                    <a href="<?php echo BASE_URL; ?>/terms.php" class="text-muted text-decoration-none me-3">Terms of Service</a>
                    <a href="<?php echo BASE_URL; ?>/refund.php" class="text-muted text-decoration-none">Refund Policy</a>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<div id="backToTop" title="Back to top">
    <i class="bi bi-arrow-up"></i>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom JS -->
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>

</body>
</html>
