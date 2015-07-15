(function($) {

  function apiGet(url, token) {
    var request = $.ajax({
      url: url,
      method: 'get',
      headers: { 'Authorization': 'Bearer ' + token,
                 'Accept': 'application/vnd.unbounce.api.v0.4+json' },
      dataType: 'json'
    });
    return Rx.Observable.fromPromise(request.promise());
  }

  function getApiResult(modelName, attributeName, result) {
    if($.isArray(result[modelName])) {
      return Rx.Observable.fromArray(
        $.map(result[modelName], function(resultModelName) {
          if(resultModelName && resultModelName[attributeName]) {
            return resultModelName[attributeName];
          } else {
            throw 'Unable to fetch ' + attributeName;
          }
        }));
    } else {
      throw 'Unable to fetch ' + first;
    }
  }

  function postDomainsToWordpress($form, domains) {
    $form.find('[name="domains"]').val(domains.join(','));
    $form.submit();
  }

  function failureUI($form, $submitButton, originalText) {
    var message = $('<div class="error">').text('Sorry, something went wrong when Authenticating with Unbounce. Please try again.');
    $form.append(message);
      $submitButton.attr('disabled', false).val(originalText);
  }

  function loadingUI($submitButton, text) {
    $submitButton.attr('disabled', true).val(text);
  }

  $(document).ready(function(){
    var $submitButton = $('#set-unbounce-domains');

    if($submitButton[0]) {
      var $form = $($submitButton[0].form),
          originalText = $submitButton.val(),
          loadingText = 'Authorizing...',
          apiUrl = $submitButton.attr('data-api-url'),
          redirectUri = $submitButton.attr('data-redirect-uri'),
          apiClientId = $submitButton.attr('data-api-client-id'),
          getTokenUrl = apiUrl + '/oauth/authorize?response_type=token&client_id=' + apiClientId + '&redirect_uri=' + redirectUri,
          getAccountsUrl = apiUrl + '/accounts',
          getSubAccountsUrl = apiUrl + '/accounts/{accountId}/sub_accounts',
          getSubAccountUrl = apiUrl + '/sub_accounts/{subAccountId}',
          getDomainsUrl = apiUrl + '/sub_accounts/{subAccountId}/domains',
          setDomainsUrl = $form.attr('action'),
          matches = location.hash.match(/access_token=([a-z0-9]+)/),
          accessToken = matches && matches[1];

      $submitButton.click(function(e) {
        e.preventDefault();

        document.location = getTokenUrl;

        return false;
      });

      if(accessToken) {
        loadingUI($submitButton, loadingText);

        var source = apiGet(getAccountsUrl, accessToken)
              .flatMap(function(accounts) {
                return getApiResult('accounts', 'id', accounts);
              })
              .flatMap(function(accountId) {
                return apiGet(getSubAccountsUrl.replace('{accountId}', accountId), accessToken);
              })
              .flatMap(function(subAccount) {
                return getApiResult('sub_accounts', 'id', subAccount);
              })
              .flatMap(function (subAccountId) {
                return apiGet(getDomainsUrl.replace('{subAccountId}', subAccountId), accessToken);
              })
              .flatMap(function(domains) {
                return getApiResult('domains', 'name', domains);
              }).toArray().publish(),
            subscription = source.subscribe(
              function (domains) {
                postDomainsToWordpress($form, domains);
              },
              function (error) {
                failureUI($form, $submitButton, originalText);
                console.log('[ub-wordpress]', error);
              },
              function () {
                // toArray will ensure that onNext is called only once. We'll consider that 'completed'
                // as we'll also have access to the list of domains
              });

        source.connect();
      }
    }
  });
})(jQuery);
