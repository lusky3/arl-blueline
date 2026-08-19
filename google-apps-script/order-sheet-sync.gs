/**
 * ARL order sync -- receives one order from WordPress and upserts it as a row.
 *
 * SETUP
 *   1. Extensions -> Apps Script, paste this file (replace the default stub).
 *   2. Project Settings -> Script properties -> Add:
 *          ARL_SHEET_SECRET = <the same secret you give WordPress>
 *   3. Deploy -> New deployment -> Web app
 *          Execute as:      Me
 *          Who has access:  Anyone
 *   4. Copy the /exec URL and hand it to WordPress (option arl_sheet_webhook_url).
 *
 * SECURITY
 *   The /exec URL is world-reachable, so every request must carry
 *       ?sig=hex(hmac_sha256(rawBody, ARL_SHEET_SECRET))
 *   Requests without a matching signature are refused. The secret itself is never transmitted.
 *
 * BEHAVIOUR
 *   Looks up the Order ID in column A. Found -> overwrites that row. Not found -> appends.
 *   So pushing the same order repeatedly is harmless, which is what makes WordPress-side
 *   retries and the one-off backfill safe.
 */

var SHEET_NAME = 'Registrations';

// Bump this whenever you paste new code, then publish a NEW VERSION of the deployment.
// GET the /exec URL to confirm which version is actually live.
var SCRIPT_VERSION = 2;

var HEADERS = [
  'Order ID', 'Order Date', 'Product Name', 'Order Status',
  'First Name', 'Last Name', 'Email',
  'Gender', 'D.o.B.', 'Position', 'Experience', 'Division', 'Returning Player',
  'Restricted', 'Requested Team', 'Requested Partner',
  'Captain', 'Requested Partner 2', 'Requested Partner 3'
];

/**
 * Health check. GET the /exec URL in a browser -- it should return this JSON.
 * If you instead see "Script function not found: doGet", the deployment is serving an OLD
 * code version: Deploy -> Manage deployments -> pencil -> Version: "New version" -> Deploy.
 * Writes nothing and reveals no secret, so it is safe to leave enabled.
 */
function doGet() {
  return json({
    ok: true,
    service: 'arl-order-sheet-sync',
    version: SCRIPT_VERSION,
    secretConfigured: !!PropertiesService.getScriptProperties().getProperty('ARL_SHEET_SECRET'),
    sheet: SHEET_NAME
  });
}

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(30000);          // two pushes for one order must not both append
  try {
    var secret = PropertiesService.getScriptProperties().getProperty('ARL_SHEET_SECRET');
    if (!secret) { return json({ ok: false, error: 'ARL_SHEET_SECRET not set in Script properties' }); }

    var body = e.postData ? e.postData.contents : '';
    var given = ((e.parameter && e.parameter.sig) || '').toLowerCase();
    if (given !== hmacHex(body, secret)) {
      return json({ ok: false, error: 'bad signature' });
    }

    var payload = JSON.parse(body);
    var values = payload.values || {};
    var sheet = getSheet();
    var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];

    // Map by header NAME, so inserting a column in the sheet cannot shift data sideways.
    var row = [];
    for (var i = 0; i < headers.length; i++) {
      var h = headers[i];
      row.push(Object.prototype.hasOwnProperty.call(values, h) ? values[h] : '');
    }

    var target = findRowByOrderId(sheet, String(payload.order_id));
    if (target > 0) {
      sheet.getRange(target, 1, 1, headers.length).setValues([row]);
    } else {
      sheet.appendRow(row);
      target = sheet.getLastRow();
    }
    return json({ ok: true, row: target });

  } catch (err) {
    return json({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

function getSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(SHEET_NAME) || ss.insertSheet(SHEET_NAME);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(HEADERS);
    sheet.setFrozenRows(1);
    sheet.getRange(1, 1, 1, HEADERS.length).setFontWeight('bold');
    // Order ID and D.o.B. as plain text, so long ids and dates are not reformatted.
    sheet.getRange(2, 1, sheet.getMaxRows() - 1, 1).setNumberFormat('@');
    sheet.getRange(2, 9, sheet.getMaxRows() - 1, 1).setNumberFormat('@');
  }
  return sheet;
}

function findRowByOrderId(sheet, orderId) {
  var last = sheet.getLastRow();
  if (last < 2) { return 0; }
  var ids = sheet.getRange(2, 1, last - 1, 1).getDisplayValues();
  for (var i = 0; i < ids.length; i++) {
    if (String(ids[i][0]).trim() === orderId) { return i + 2; }
  }
  return 0;
}

function hmacHex(message, key) {
  var raw = Utilities.computeHmacSha256Signature(message, key);
  return raw.map(function (b) {
    var v = (b < 0 ? b + 256 : b).toString(16);
    return v.length === 1 ? '0' + v : v;
  }).join('');
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
