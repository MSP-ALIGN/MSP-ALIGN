<?php
use Align\Branding;

/**
 * Branding (1.45.2): the 1.43 look. Name and logo, the sign-in page, the brand color and the sidebar on the left;
 * on the right a live preview of the app, the sign-in page and the client portal as they look now, in light or dark.
 * @var array $v; bool $hasLogo; string $logoUrl; array $backgrounds [staff|portal => [url, dim]] (2.1.1);
 *      bool $hasLightLogo; string $lightLogoUrl (2.2.4: a light mode logo; $hasLogo / $logoUrl are the dark mode one)
 * Security: $v['brand_primary'] is Branding::color() (always #rrggbb), so it is safe in the style attributes; the
 * background URLs are built by Branding from fixed parts and the dims are integers. Text values are escaped.
 */
// 2.2.2: the default is the MSP Align logo's blue; its teal and navy, and the old default, are choices too
$swatches = ['#1b68b8' => 'Default blue', '#0b7d88' => 'MSP Align teal', '#0f2a4f' => 'MSP Align navy', '#007bff' => 'Bright blue', '#2f7a55' => 'Mountain green', '#6f42c1' => 'Purple', '#b3261e' => 'Red', '#e67e22' => 'Orange', '#343a40' => 'Charcoal'];
$company = (string) ($v['company_name'] ?: 'Your company');
// 2.2.4: which logo each place uses: light backgrounds the light mode logo, dark ones the dark mode logo (either
// stands in for the other)
$lightUrl = $hasLightLogo ? $lightLogoUrl : $logoUrl;
$darkUrl = $hasLogo || !$hasLightLogo ? $logoUrl : $lightLogoUrl;
$menuUrl = $v['brand_sidebar'] === 'light' ? $lightUrl : $darkUrl;
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'branding']) ?>

<form method="post" action="/settings/branding" enctype="multipart/form-data" id="branding-form" data-unsaved>
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-xl-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-signature me-2"></i>Name &amp; logo</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="brand_name">App name</label>
              <input name="brand_name" id="brand_name" class="form-control" maxlength="60" value="<?= e($v['brand_name']) ?>" placeholder="<?= e(Branding::DEFAULT_NAME) ?>" data-preview="name">
              <div class="form-text">In the menu, the browser tab, the sign-in page and authenticator apps (new 2FA setups).</div>
            </div>
            <div class="col-md-6">
              <label for="company_name">Company name</label>
              <input name="company_name" id="company_name" class="form-control" maxlength="190" value="<?= e($v['company_name']) ?>" data-preview="company">
              <div class="form-text">On reports and in the client portal. Phone, email and the report footer are on <a href="/settings">General</a>.</div>
            </div>
          </div>
          <hr class="my-3">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="d-block" for="logo_light"><i class="fas fa-sun me-1 text-warning"></i>Light mode logo</label>
              <div class="brand-logo-drop is-light"><img src="<?= e($lightLogoUrl) ?>" alt="Current light mode logo" id="light-logo-img"<?= $hasLightLogo ? '' : ' class="opacity-50"' ?>></div>
              <input type="file" class="form-control mt-2" id="logo_light" name="logo_light" accept="image/png,image/jpeg,image/webp,image/gif">
              <div class="form-text">For light backgrounds: the sign-in page in light mode, printed reports, contracts and their PDFs, emails, the client portal, onboarding pages, a light menu and the browser tab. Use a dark or colored logo that reads on white.</div>
              <?php if ($hasLightLogo): ?>
                <button class="btn btn-sm btn-link text-danger px-0" name="action" value="remove_logo_light" formnovalidate data-confirm="Remove the light mode logo?<?= $hasLogo ? ' The dark mode logo is used everywhere then.' : ' The default icon comes back.' ?>"><i class="fas fa-trash me-1"></i>Remove light mode logo</button>
              <?php else: ?>
                <div class="form-text"><i class="fas fa-circle-info me-1"></i><?= $hasLogo ? 'Empty: using the dark mode logo.' : 'Empty: showing the default icon.' ?></div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="d-block" for="logo"><i class="fas fa-moon me-1 text-secondary"></i>Dark mode logo</label>
              <div class="brand-logo-drop is-dark"><img src="<?= e($logoUrl) ?>" alt="Current dark mode logo" id="logo-img"<?= $hasLogo || !$hasLightLogo ? '' : ' class="opacity-50"' ?>></div>
              <input type="file" class="form-control mt-2" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
              <div class="form-text">For dark backgrounds: the dark menu and the sign-in page in dark mode. A light or white logo works best.</div>
              <?php if ($hasLogo): ?>
                <button class="btn btn-sm btn-link text-danger px-0" name="action" value="remove_logo" formnovalidate data-confirm="Remove the dark mode logo?<?= $hasLightLogo ? ' The light mode logo is used everywhere then.' : ' The default icon comes back.' ?>"><i class="fas fa-trash me-1"></i>Remove dark mode logo</button>
              <?php else: ?>
                <div class="form-text"><i class="fas fa-circle-info me-1"></i><?= $hasLightLogo ? 'Empty: using the light mode logo.' : 'Empty: showing the default icon.' ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="form-text mt-2">PNG, JPG, WebP or GIF up to 2 MB each. A square icon or a wide wordmark both work. Upload just one and it's used everywhere.</div>
          <div class="form-check form-switch mt-3 mb-0">
            <input type="checkbox" class="form-check-input" role="switch" id="logo-only" name="brand_logo_only" value="1" <?= $v['brand_logo_only'] ? 'checked' : '' ?> data-preview="logo-only">
            <label class="form-check-label fw-normal" for="logo-only">My logo already includes our name: hide the app name next to it</label>
          </div>
        </div>
      </div>

      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-palette me-2"></i>Colors</h3></div>
        <div class="card-body">
          <label for="brand_primary">Brand color</label>
          <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="input-group brand-color-input">
              <input type="color" class="form-control form-control-color" value="<?= e($v['brand_primary']) ?>" data-color-for="brand_primary" aria-label="Pick a color">
              <input name="brand_primary" id="brand_primary" class="form-control font-monospace" value="<?= e($v['brand_primary']) ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" data-preview="color">
            </div>
            <div class="brand-swatches" role="group" aria-label="Suggested colors">
              <?php foreach ($swatches as $hex => $label): ?>
                <button type="button" class="swatch<?= strtolower($v['brand_primary']) === $hex ? ' is-active' : '' ?>" style="background: <?= $hex ?>" data-swatch="<?= $hex ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"></button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-text mb-3">Buttons, links, the selected menu item, tabs, focus rings, charts and the client portal, in light and dark mode. Text on it turns dark or white, whichever reads better (now <b id="brand-text-label"><?= Branding::contrastText($v['brand_primary']) === '#ffffff' ? 'white' : 'dark' ?></b>).</div>

          <label class="d-block">Menu</label>
          <div class="theme-picker d-flex flex-wrap gap-3" role="radiogroup" aria-label="Menu color">
            <?php foreach (['dark' => ['Dark', 'fa-moon', 'The usual: the logo and menu on dark gray.'], 'light' => ['Light', 'fa-sun', 'White, for a logo that needs a light background.']] as $k => [$label, $icon, $help]): ?>
              <label class="theme-option brand-sidebar-option<?= $v['brand_sidebar'] === $k ? ' is-active' : '' ?>">
                <input type="radio" class="visually-hidden" name="brand_sidebar" value="<?= $k ?>" <?= $v['brand_sidebar'] === $k ? 'checked' : '' ?> data-preview="sidebar">
                <span class="theme-swatch brand-sidebar-swatch-<?= $k ?>" aria-hidden="true"><span></span><span></span></span>
                <span><i class="fas fa-fw <?= $icon ?> me-1"></i><?= $label ?></span>
                <span class="small text-muted fw-normal"><?= $help ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-right-to-bracket me-2"></i>Sign-in page</h3></div>
        <div class="card-body">
          <label for="brand_login_message">Message above the form</label>
          <input name="brand_login_message" id="brand_login_message" class="form-control" maxlength="200" value="<?= e($v['brand_login_message']) ?>" placeholder="Sign in to continue" data-preview="message">
          <div class="form-text">For staff. Client portal users see their own sign-in page with your logo.</div>
          <?php foreach (Branding::BG_KINDS as $bk => $bl): $bg = $backgrounds[$bk]; ?>
            <hr class="my-3">
            <label class="d-block" for="bg_<?= $bk ?>"><?= e($bl) ?> background</label>
            <div class="d-flex flex-wrap align-items-start gap-3">
              <div>
                <div class="brand-bg-thumb<?= $bg['url'] ? '' : ' is-empty' ?>"<?= $bg['url'] ? ' style="background-image: url(&quot;' . e($bg['url']) . '&quot;)"' : '' ?>><?= $bg['url'] ? '' : '<span>No image</span>' ?></div>
                <div class="small text-muted text-center mt-1"><?= ['custom' => 'Your image', 'default' => 'Built-in image', 'none' => 'Plain page'][$bg['mode']] ?></div>
              </div>
              <div class="flex-grow-1" style="min-width: 220px">
                <input type="file" class="form-control" id="bg_<?= $bk ?>" name="bg_<?= $bk ?>" accept="image/jpeg,image/png,image/webp">
                <div class="row g-2 align-items-center mt-1">
                  <div class="col-auto"><label class="small mb-0 fw-normal" for="bg_<?= $bk ?>_dim">Darken it</label></div>
                  <div class="col-auto"><select class="form-select form-select-sm" id="bg_<?= $bk ?>_dim" name="bg_<?= $bk ?>_dim">
                    <?php foreach (Branding::BG_DIMS as $dv => $dl): ?><option value="<?= $dv ?>" <?= $bg['dim'] === $dv ? 'selected' : '' ?>><?= e($dl) ?></option><?php endforeach; ?>
                  </select></div>
                </div>
                <div class="form-text"><?= $bk === 'staff' ? 'Behind your team\'s sign-in.' : 'Behind the sign-in your clients see: something neutral works best.' ?> Upload your own to replace the built-in one. JPG, PNG or WebP up to 8 MB, 1920 × 1080 or larger. Darkening keeps your logo and name readable on a busy photo.</div>
                <div class="d-flex flex-wrap gap-3">
                  <?php if ($bg['mode'] === 'custom'): ?>
                    <button class="btn btn-sm btn-link text-danger px-0" name="action" value="remove_bg_<?= $bk ?>" formnovalidate data-confirm="Remove your <?= e(strtolower($bl)) ?> background? The built-in one comes back."><i class="fas fa-trash me-1"></i>Remove my image</button>
                  <?php elseif ($bg['mode'] === 'none'): ?>
                    <button class="btn btn-sm btn-link px-0" name="action" value="default_bg_<?= $bk ?>" formnovalidate><i class="fas fa-image me-1"></i>Use the built-in image</button>
                  <?php endif; ?>
                  <?php if ($bg['mode'] !== 'none'): ?>
                    <button class="btn btn-sm btn-link text-secondary px-0" name="action" value="plain_bg_<?= $bk ?>" formnovalidate<?= $bg['mode'] === 'custom' ? ' data-confirm="Remove your ' . e(strtolower($bl)) . ' background and use a plain page?"' : '' ?>><i class="fas fa-ban me-1"></i>No image</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save branding</button>
        <button class="btn btn-default" name="action" value="reset" formnovalidate data-confirm="Reset the name and colors to the defaults? Your logos and sign-in backgrounds are kept.">Reset to defaults</button>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="card card-dark brand-preview-card">
        <div class="card-header py-2 d-flex align-items-center">
          <h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-eye me-2"></i>Preview</h3>
          <div class="btn-group btn-group-sm" role="group" aria-label="Preview in light or dark mode">
            <button type="button" class="btn btn-default active" data-preview-theme="light" aria-pressed="true"><i class="fas fa-sun me-1"></i>Light</button>
            <button type="button" class="btn btn-default" data-preview-theme="dark" aria-pressed="false"><i class="fas fa-moon me-1"></i>Dark</button>
          </div>
        </div>
        <div class="card-body">
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#bp-tab-app" role="tab" aria-selected="true">App</button></li>
            <li class="nav-item" role="presentation"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#bp-tab-login" role="tab" aria-selected="false">Sign-in page</button></li>
            <li class="nav-item" role="presentation"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#bp-tab-portal" role="tab" aria-selected="false">Client portal</button></li>
          </ul>
          <div class="bp-frame" id="brand-preview" data-bs-theme="light" data-has-dark="<?= $hasLogo ? '1' : '0' ?>" data-has-light="<?= $hasLightLogo ? '1' : '0' ?>" data-builtin="/assets/icon.png" data-default-name="<?= e(Branding::DEFAULT_NAME) ?>" data-default-company="Your company"
            style="--bp-color: <?= e($v['brand_primary']) ?>; --bp-text: <?= e(Branding::contrastText($v['brand_primary'])) ?>">
            <div class="tab-content">
              <div class="tab-pane fade show active" id="bp-tab-app" role="tabpanel">
                <div class="bp-app">
                  <div class="bp-side<?= $v['brand_sidebar'] === 'light' ? ' is-light' : '' ?>" id="bp-side">
                    <div class="bp-brand"><img src="<?= e($menuUrl) ?>" alt="" class="bp-logo" data-logo="menu"><span class="bp-name<?= $v['brand_logo_only'] ? ' d-none' : '' ?>"><?= e($v['brand_name']) ?></span></div>
                    <div class="bp-item is-active"><i class="fas fa-fw fa-gauge-high"></i>Dashboard</div>
                    <div class="bp-item"><i class="fas fa-fw fa-list-check"></i>To do <span class="bp-count">4</span></div>
                    <div class="bp-head">Clients</div>
                    <div class="bp-item"><i class="fas fa-fw fa-users"></i>Clients</div>
                    <div class="bp-item"><i class="fas fa-fw fa-desktop"></i>Devices &amp; assets</div>
                    <div class="bp-head">Planning</div>
                    <div class="bp-item"><i class="fas fa-fw fa-diagram-project"></i>Projects</div>
                    <div class="bp-item"><i class="fas fa-fw fa-coins"></i>Budgets</div>
                  </div>
                  <div class="bp-main">
                    <div class="bp-header"><i class="fas fa-bars"></i><span class="bp-search"><i class="fas fa-magnifying-glass me-1"></i>Search clients, devices…</span><span class="bp-avatar">AA</span></div>
                    <div class="bp-content">
                      <div class="bp-title">Dashboard</div>
                      <div class="bp-card">
                        <div class="bp-card-head">Needs attention <span class="bp-link">View all</span></div>
                        <div class="bp-row"><span class="bp-dot bad"></span>Cedar Ridge Dental: 2 servers past end of life</div>
                        <div class="bp-row"><span class="bp-dot warn"></span>Harbor Point Law: backup failed last night</div>
                        <div class="bp-row"><span class="bp-dot"></span>Next QBR with Northfield Hardware in 6 days</div>
                      </div>
                      <div class="bp-actions"><span class="bp-btn">Save</span><span class="bp-btn-outline">Cancel</span><span class="bp-pill">Approved</span></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="bp-tab-login" role="tabpanel">
                <?php $sb = $backgrounds['staff']; ?>
                <div class="bp-login<?= $sb['url'] ? ' has-bg' : '' ?>"<?= $sb['url'] ? ' style="background-image: linear-gradient(rgba(0,0,0,' . ($sb['dim'] / 100) . '), rgba(0,0,0,' . ($sb['dim'] / 100) . ')), url(&quot;' . e($sb['url']) . '&quot;)"' : '' ?>>
                  <div class="bp-login-logo"><img src="<?= e($lightUrl) ?>" alt="" class="bp-logo bp-on-light" data-logo="light"><img src="<?= e($darkUrl) ?>" alt="" class="bp-logo bp-on-dark" data-logo="dark"><b class="bp-name<?= $v['brand_logo_only'] ? ' d-none' : '' ?>"><?= e($v['brand_name']) ?></b></div>
                  <div class="bp-login-card">
                    <div class="bp-login-msg" id="bp-message"><?= e($v['brand_login_message'] ?: 'Sign in to continue') ?></div>
                    <div class="bp-field">Email</div><div class="bp-field">Password</div>
                    <span class="bp-btn bp-btn-block">Sign in</span>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="bp-tab-portal" role="tabpanel">
                <div class="bp-portal">
                  <div class="bp-portal-top">
                    <span class="bp-client-badge">CR</span>
                    <span class="bp-portal-name"><b>Cedar Ridge Family Dental</b><small>IT portal · <span class="bp-company"><?= e($company) ?></span></small></span>
                    <span class="bp-avatar ms-auto">JE</span>
                  </div>
                  <div class="bp-portal-tabs"><span class="is-active"><i class="fas fa-house me-1"></i>Home</span><span><i class="fas fa-route me-1"></i>Roadmap</span><span><i class="fas fa-coins me-1"></i>Budget</span><span><i class="fas fa-desktop me-1"></i>Devices</span></div>
                  <div class="bp-content">
                    <div class="bp-card">
                      <div class="bp-card-head">Waiting for your decision</div>
                      <div class="bp-row">Replace the front desk PCs · Q1 2027 <span class="bp-btn ms-auto">Approve</span></div>
                      <div class="bp-row">Move email to Microsoft 365 · Q2 2027 <span class="bp-link ms-auto">Details</span></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <p class="small text-muted mb-0 mt-2">Each person picks light or dark for themselves under Account; the brand color and logo are the same in both. Printed reports always use the light colors.</p>
        </div>
      </div>
    </div>
  </div>
</form>
