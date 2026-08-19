/**
 * ARL: registration-rules acknowledgement gate.
 *
 * Flow on this site is /register -> "Register Now" -> straight to /checkout
 * (snippet 49 sets woocommerce_add_to_cart_redirect). So the add-to-cart click is
 * the real decision point, and that is where the modal fires.
 *
 * The player ticks ONCE, in the modal. The acknowledgement + rules version + timestamp
 * ride along in the cart item data and land on the order line item, so it shows on the
 * order screen and in Advanced Order Export.
 *
 * NOTE: the page check happens on `wp`, not inside a later hook -- the [product_page]
 * shortcode replaces the main query while it renders, after which is_page() is false
 * and get_queried_object() is NULL.
 */
if ( class_exists( 'WooCommerce' ) ) {

	function arl_rules_version() {
		return 'W2026-27';
	}

	function arl_rules_required_for( $product_id ) {
		// Registration category (product_cat 91) -- covers this season and future ones.
		return has_term( 91, 'product_cat', $product_id );
	}

	/* ---------- 1. Modal markup + click interception on /register ---------- */

	add_action( 'wp', function () {
		// The Register page, and also the registration product pages themselves --
		// those are publicly reachable, and the server-side backstop below would
		// otherwise block a direct add-to-cart with no way for the player to consent.
		$show = is_page( 11113 );
		if ( ! $show && function_exists( 'is_product' ) && is_product() ) {
			$show = arl_rules_required_for( get_queried_object_id() );
		}
		if ( $show ) {
			add_action( 'wp_footer', 'arl_rules_modal', 20 );
		}
	} );

	function arl_rules_modal() {
		$faq     = esc_url( home_url( '/faqs#winter-2026-27' ) );
		$version = esc_attr( arl_rules_version() );

		echo <<<HTML
<div id="arl-rules-modal" role="dialog" aria-modal="true" aria-labelledby="arl-rules-title" data-arl-version="{$version}" hidden>
  <div class="arl-rules-backdrop" data-arl-close></div>
  <div class="arl-rules-panel">
    <h2 id="arl-rules-title">Before you register &mdash; Winter 2026-27 roster changes</h2>
    <p>Teams are built differently this winter. Here is what it means for you:</p>
    <ul>
      <li><strong>You may not be on the same team as last season.</strong> Around half of every roster is now filled by the league, so expect some new teammates.</li>
      <li><strong>Your registration form comes first.</strong> What you ask for on your own form takes precedence over any captain&rsquo;s request list.</li>
      <li><strong>Playing with a friend?</strong> Register as a pair on your individual forms and we will make every effort to keep you together.</li>
      <li><strong>You can ask not to be matched</strong> with a particular player or team &mdash; note it confidentially on your form.</li>
      <li><strong>Not requested by a captain? That is fine.</strong> The league places you on a roster, and that is now a normal route onto a team.</li>
      <li><strong>Divisions are balanced by skill</strong> &mdash; from past stats, captain feedback and convener evaluations, or your questionnaire if you are new. The ARL may move a player to keep things fair.</li>
    </ul>
    <p><strong>Captains:</strong> you may submit a priority list of up to seven players, including your goalie. The FAQ covers how those lists are handled.</p>
    <p><a href="{$faq}" target="_blank" rel="noopener">Read the full FAQ</a></p>
    <label class="arl-rules-confirm">
      <input type="checkbox" id="arl-rules-agree">
      <span>I confirm I have read and agree to the updated registration rules.</span>
    </label>
    <div class="arl-rules-actions">
      <button type="button" class="button" data-arl-close>Cancel</button>
      <button type="button" class="button alt" id="arl-rules-continue" disabled>Continue to registration</button>
    </div>
  </div>
</div>
<style>
#arl-rules-modal[hidden]{display:none}
#arl-rules-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center}
#arl-rules-modal .arl-rules-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.6)}
#arl-rules-modal .arl-rules-panel{position:relative;background:#fff;color:#222;max-width:640px;width:calc(100% - 2rem);max-height:85vh;overflow:auto;padding:1.5rem 1.75rem;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,.35)}
#arl-rules-modal h2{margin-top:0;font-size:1.6rem;line-height:1.25;font-weight:700}
#arl-rules-modal ul{margin:0 0 1rem 1.1rem}
#arl-rules-modal li{margin-bottom:.5rem}
#arl-rules-modal .arl-rules-confirm{display:flex;gap:.6rem;align-items:flex-start;margin:1rem 0;font-weight:600}
#arl-rules-modal .arl-rules-actions{display:flex;gap:.75rem;justify-content:flex-end;flex-wrap:wrap}
#arl-rules-modal #arl-rules-continue[disabled]{opacity:.5;cursor:not-allowed}
</style>
<script>
(function(){
  var modal=document.getElementById('arl-rules-modal');
  if(!modal) return;
  var agree=document.getElementById('arl-rules-agree');
  var go=document.getElementById('arl-rules-continue');
  var form=null, submitter=null;
  function openModal(f,btn){form=f;submitter=btn;agree.checked=false;go.disabled=true;modal.hidden=false;document.body.style.overflow='hidden';agree.focus();}
  function hideModal(){modal.hidden=true;document.body.style.overflow='';}
  function cancel(){hideModal();form=null;submitter=null;}
  function setHidden(f,name,value){
    if(!name) return;
    var el=f.querySelector('input[type="hidden"][name="'+name+'"]');
    if(!el){el=document.createElement('input');el.type='hidden';el.name=name;f.appendChild(el);}
    el.value=value;
  }
  agree.addEventListener('change',function(){go.disabled=!agree.checked;});
  Array.prototype.forEach.call(modal.querySelectorAll('[data-arl-close]'),function(el){el.addEventListener('click',cancel);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)cancel();});
  go.addEventListener('click',function(){
    if(!form||!agree.checked) return;
    var f=form, btn=submitter;
    setHidden(f,'arl_rules_ack','1');
    setHidden(f,'arl_rules_version',modal.getAttribute('data-arl-version')||'');
    if(btn&&btn.name){setHidden(f,btn.name,btn.value);}
    f.dataset.arlAcked='1';
    hideModal(); form=null; submitter=null;
    var sent=false;
    if(typeof f.requestSubmit==='function'){
      try{ if(btn){f.requestSubmit(btn);}else{f.requestSubmit();} sent=true; }catch(err){ sent=false; }
    }
    if(!sent){ f.submit(); }
  });
  document.addEventListener('submit',function(e){
    var f=e.target;
    if(!f.classList||!f.classList.contains('cart')) return;
    if(f.dataset.arlAcked==='1') return;
    e.preventDefault();e.stopPropagation();
    openModal(f, e.submitter || f.querySelector('button[type="submit"], input[type="submit"]'));
  },true);
})();
</script>
HTML;
	}

	/* ---------- 2. Server-side backstop: no acknowledgement, no add to cart ---------- */

	add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
		if ( ! arl_rules_required_for( $product_id ) ) {
			return $passed;
		}
		if ( empty( $_POST['arl_rules_ack'] ) ) {
			wc_add_notice(
				'Please confirm you have read the updated registration rules before registering.',
				'error'
			);
			return false;
		}
		return $passed;
	}, 10, 2 );

	/* ---------- 3. Carry the acknowledgement onto the cart item ---------- */

	add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id ) {
		if ( arl_rules_required_for( $product_id ) && ! empty( $_POST['arl_rules_ack'] ) ) {
			$version = isset( $_POST['arl_rules_version'] )
				? sanitize_text_field( wp_unslash( $_POST['arl_rules_version'] ) )
				: arl_rules_version();
			$data['arl_rules_ack'] = array(
				'version'  => $version,
				'accepted' => current_time( 'mysql' ),
			);
		}
		return $data;
	}, 10, 2 );

	/* ---------- 4. ...and onto the order line item ---------- */

	add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['arl_rules_ack'] ) ) {
			return;
		}
		$ack = $values['arl_rules_ack'];
		$item->add_meta_data(
			'Registration Rules Accepted',
			sprintf( '%s (%s)', $ack['version'], $ack['accepted'] ),
			true
		);
		$order->update_meta_data( '_arl_rules_version', $ack['version'] );
		$order->update_meta_data( '_arl_rules_accepted', $ack['accepted'] );
	}, 10, 4 );

}
