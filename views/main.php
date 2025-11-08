<?php
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>DB Assistant (Prod)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
  <style>
    .result { background: #f6f8fa; padding: 12px; border-radius: 8px; border: 1px solid #e5e7eb; white-space: pre-wrap; }
    details { margin-top: 18px; }
  </style>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta http-equiv="Pragma" content="no-cache" />
  </head>
<body>
  <section class="section">
    <div class="container">
      <h1 class="title">Natural Language DB Assistant</h1>
      <p class="subtitle">Single box for read/write over approved tables. Secure-by-default, with write confirmation.</p>

      <form method="post" class="box">
        <div class="field">
          <label for="nl" class="label">What do you want to do?</label>
          <div class="control">
            <textarea id="nl" name="nl" class="textarea" placeholder="Examples: 
- Top 10 items in last 365 days
- Top items in October 2024
- Add a product called Pixel 9 (SKU PIX9-128-BLK) priced 699.99 with 25 in stock
- Update stock for SKU IP14-128-BLK to 60"></textarea>
          </div>
        </div>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">
        <div class="field is-grouped is-align-items-center">
          <div class="control">
            <button type="submit" class="button is-primary">Submit</button>
          </div>
          <div class="control">
            <button type="button" id="toggleExamples" class="button is-light is-small">Show examples</button>
          </div>
          <?php if ($modeTag): ?>
            <div class="control" style="margin-left:8px;">
              <span class="tag is-link is-light"><?= htmlspecialchars($modeTag) ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div id="examplesPanel" style="display:none; margin: 6px 0 12px 0;">
          <?php
            $examples = [
              'Top 10 items in last 365 days',
              'Top items in October 2024',
              'List all products with stock < 20',
              'Add a product called Pixel 9 (SKU PIX9-128-BLK) priced 699.99 with 25 in stock',
              'Update stock for SKU IP14-128-BLK to 60',
            ];
          ?>
          <?php foreach ($examples as $ex): ?>
            <button type="button" class="button is-small is-light ex-chip" data-text="<?= htmlspecialchars($ex) ?>" style="margin:4px;">
              <?= htmlspecialchars($ex) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </form>

      <?php if ($feedback): ?>
        <div class="box" style="margin-top:16px;">
          <h3 class="title is-5">Response</h3>
          <div class="content">
            <div class="notification is-light">
              <?= nl2br(htmlspecialchars($feedback)) ?>
            </div>
          </div>
          <?php if ($provReason || $provSql || $executedOp || $proposedOp): ?>
            <details style="margin-top:10px;">
              <summary><strong>Provenance (what was planned/executed)</strong></summary>
              <?php if ($provReason): ?>
                <p><strong>Reason:</strong> <?= htmlspecialchars($provReason) ?></p>
              <?php endif; ?>
              <?php if ($provSql): ?>
                <p><strong>SQL:</strong></p>
                <pre class="result" id="sqlText"><?= htmlspecialchars($provSql) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#sqlText">Copy SQL</button>
              <?php endif; ?>
              <?php if ($executedOp): ?>
                <p><strong>Executed Operation:</strong></p>
                <pre class="result" id="opText"><?= htmlspecialchars(json_encode($executedOp, JSON_PRETTY_PRINT)) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#opText">Copy Operation</button>
              <?php elseif ($proposedOp): ?>
                <p><strong>Proposed Operation:</strong></p>
                <pre class="result" id="opText"><?= htmlspecialchars(json_encode($proposedOp, JSON_PRETTY_PRINT)) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#opText">Copy Operation</button>
              <?php endif; ?>
              <?php if ($errorRaw): ?>
                <p><strong>DB Error (raw):</strong></p>
                <pre class="result"><?= htmlspecialchars($errorRaw) ?></pre>
              <?php endif; ?>
            </details>
          <?php endif; ?>
          <?php if (!empty($_SESSION['last_base_sql'])): ?>
            <div class="field is-grouped" style="margin-top:10px;">
              <form method="post" class="control">
                <input type="hidden" name="paginate" value="1">
                <input type="hidden" name="page" value="<?= max(1, $page-1) ?>">
                <input type="hidden" name="page_size" value="<?= (int)($pageSize) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <button class="button is-small is-light" type="submit" <?= $page <= 1 ? 'disabled' : '' ?>>Prev</button>
              </form>
              <form method="post" class="control">
                <input type="hidden" name="paginate" value="1">
                <input type="hidden" name="page" value="<?= (int)($page+1) ?>">
                <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <button class="button is-small is-light" type="submit">Next</button>
              </form>
              <div class="control" style="margin-left:8px; align-self:center;">
                <span class="tag is-light">Page <?= (int)$page ?><?= is_int($totalCount ?? null) ? (' / ' . max(1, (int)ceil($totalCount / max(1,$pageSize)) )) : '' ?></span>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($resultPayload !== null): ?>
            <details>
              <summary>Show raw payload</summary>
              <pre class="result" id="payloadText"><?= htmlspecialchars(json_encode($resultPayload, JSON_PRETTY_PRINT)) ?></pre>
              <div class="buttons">
                <button class="button is-small is-light" type="button" data-copy-target="#payloadText">Copy JSON</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="download_csv" value="1" />
                  <button class="button is-small is-link is-light" type="submit">Download CSV</button>
                </form>
              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($proposedOp): ?>
        <div class="box" style="margin-top:16px;">
          <h3 class="title is-5">Confirm Write</h3>
          <article class="message is-warning">
            <div class="message-body">
              <p>This change will be applied to table <strong><?= htmlspecialchars($proposedOp['table']) ?></strong>:</p>
              <pre class="result"><?= htmlspecialchars(json_encode($proposedOp, JSON_PRETTY_PRINT)) ?></pre>
              <form method="post">
                <input type="hidden" name="confirm_write" value="1" />
                <input type="hidden" name="proposed_op" value='<?= htmlspecialchars(json_encode($proposedOp)) ?>' />
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <button type="submit" class="button is-warning is-light">Confirm and Apply</button>
              </form>
            </div>
          </article>
        </div>
      <?php endif; ?>

      <details>
        <summary><strong>Show database schema context</strong></summary>
        <pre class="result"><?= htmlspecialchars($schemaBlock) ?></pre>
        <hr>
        <p class="subtitle is-6">Exposed tables: <?= htmlspecialchars(implode(', ', array_keys($exposedTables))) ?></p>
      </details>
    </div>
  </section>
</body>
<script>
  (function(){
    var btn = document.getElementById('toggleExamples');
    var panel = document.getElementById('examplesPanel');
    if (btn && panel) {
      btn.addEventListener('click', function(){
        var show = panel.style.display === 'none';
        panel.style.display = show ? 'block' : 'none';
        btn.textContent = show ? 'Hide examples' : 'Show examples';
      });
    }
    var chips = document.querySelectorAll('.ex-chip');
    chips.forEach(function(ch){
      ch.addEventListener('click', function(){
        var t = ch.getAttribute('data-text') || '';
        var ta = document.getElementById('nl');
        if (ta) { ta.value = t; ta.focus(); }
      });
    });
  })();
  (function(){
    function copyFromSelector(sel){
      var el = document.querySelector(sel);
      if (!el) return;
      var text = el.innerText || el.textContent || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e) {}
        document.body.removeChild(ta);
      }
    }
    document.querySelectorAll('[data-copy-target]').forEach(function(btn){
      btn.addEventListener('click', function(){ copyFromSelector(btn.getAttribute('data-copy-target')); });
    });
  })();
</script>
<?php $csrfVal = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>
</html>

