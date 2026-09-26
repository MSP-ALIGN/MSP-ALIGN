<?php use Align\Branding; ?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'branding']) ?>

<form method="post" action="/settings/branding" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="MAX_FILE_SIZE" value="<?= Branding::MAX_BYTES ?>">
  <div class="row">
    <div class="col-lg-7">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-signature mr-2"></i>Portal name &amp; logo</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Portal name</label>
              <input name="brand_name" class="form-control" maxlength="60" value="<?= e($v['brand_name']) ?>" placeholder="<?= e(Branding::DEFAULT_NAME) ?>" data-preview="name">
              <small class="text-muted">Shown in the sidebar, browser tab, sign-in page, and authenticator apps (for new 2FA setups).</small>
            </div>
            <div class="form-group col-md-6">
              <label>Company name <small class="text-muted">(reports)</small></label>
              <input name="company_name" class="form-control" maxlength="190" value="<?= e($v['company_name']) ?>">
              <small class="text-muted">Printed at the top of reports. Phone, email and footer are under Settings → General.</small>
            </div>
          </div>

          <div class="form-group">
            <label>Logo</label>
            <div class="d-flex align-items-center">
              <div class="logo-preview mr-3"><img src="<?= e($logoUrl) ?>" alt="Current logo" id="logo-img"></div>
              <div class="flex-grow-1">
                <div class="custom-file">
                  <input type="file" class="custom-file-input" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                  <label class="custom-file-label" for="logo">Choose PNG, JPG or WebP…</label>
                </div>
                <small class="text-muted d-block mt-1">Up to 2 MB. A square icon or a wide wordmark both work; transparent PNG looks best on the dark sidebar. It's also used as the browser icon and on printed reports.</small>
              </div>
            </div>
            <?php if ($hasLogo): ?>
              <button class="btn btn-xs btn-outline-danger mt-2" name="action" value="remove_logo" formnovalidate data-confirm="Remove the uploaded logo?"><i class="fas fa-trash mr-1"></i>Remove logo</button>
            <?php endif; ?>
          </div>
          <div class="custom-control custom-checkbox mb-3">
            <input type="checkbox" class="custom-control-input" id="logo-only" name="brand_logo_only" value="1" <?= $v['brand_logo_only'] ? 'checked' : '' ?> data-preview="logo-only">
            <label class="custom-control-label font-weight-normal" for="logo-only">My logo already includes our name — hide the text next to it</label>
          </div>
          <div class="form-group mb-0">
            <label>Sign-in page message</label>
            <input name="brand_login_message" class="form-control" maxlength="200" value="<?= e($v['brand_login_message']) ?>" placeholder="Sign in to continue">
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-palette mr-2"></i>Colors</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-6">
              <label>Brand color</label>
              <div class="input-group">
                <div class="input-group-prepend"><input type="color" class="form-control color-swatch" value="<?= e($v['brand_primary']) ?>" data-color-for="brand_primary" aria-label="Pick color"></div>
                <input name="brand_primary" id="brand_primary" class="form-control" value="<?= e($v['brand_primary']) ?>" pattern="#[0-9a-fA-F]{6}" data-preview="color">
              </div>
              <small class="text-muted">Top bar, buttons, active menu, charts.</small>
            </div>
            <div class="form-group col-6">
              <label>Sidebar</label>
              <select name="brand_sidebar" class="form-control" data-preview="sidebar">
                <option value="dark" <?= $v['brand_sidebar'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                <option value="light" <?= $v['brand_sidebar'] === 'light' ? 'selected' : '' ?>>Light</option>
              </select>
            </div>
          </div>
          <div class="d-flex flex-wrap mb-3">
            <?php foreach (['#007bff' => 'Default blue', '#2f7a55' => 'Mountain green', '#1d4e89' => 'Navy', '#b3261e' => 'Red', '#6f42c1' => 'Purple', '#e67e22' => 'Orange', '#343a40' => 'Charcoal', '#0f766e' => 'Teal'] as $hex => $label): ?>
              <button type="button" class="swatch mr-1 mb-1" style="background: <?= $hex ?>" data-swatch="<?= $hex ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"></button>
            <?php endforeach; ?>
          </div>

          <label class="small text-muted text-uppercase">Preview</label>
          <div class="brand-preview border rounded overflow-hidden" id="brand-preview" data-default-name="<?= e(Branding::DEFAULT_NAME) ?>">
            <div class="bp-top" id="bp-top"><span class="bp-burger">☰</span><span class="bp-search"></span></div>
            <div class="d-flex">
              <div class="bp-side <?= $v['brand_sidebar'] === 'light' ? 'is-light' : '' ?>" id="bp-side">
                <div class="bp-brand"><img src="<?= e($logoUrl) ?>" alt="" id="bp-logo"><span id="bp-name" class="<?= $v['brand_logo_only'] ? 'd-none' : '' ?>"><?= e($v['brand_name']) ?></span></div>
                <div class="bp-item is-active" id="bp-active">Dashboard</div><div class="bp-item">Clients</div><div class="bp-item">Calendar</div>
              </div>
              <div class="bp-body"><div class="bp-card"></div><span class="bp-btn" id="bp-btn">Button</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="d-flex">
    <button class="btn btn-primary mr-2" name="action" value="save"><i class="fas fa-check mr-1"></i>Save branding</button>
    <button class="btn btn-default" name="action" value="reset" formnovalidate data-confirm="Reset name and colors to the defaults?">Reset to defaults</button>
  </div>
</form>
