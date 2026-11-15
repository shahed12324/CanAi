import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

/// Every failure becomes an ApiError with a stable [code], so screens never parse messages.
class ApiError implements Exception {
  ApiError(this.status, this.code, this.message, [this.fields = const {}]);
  final int status;
  final String code;
  final String message;
  final Map<String, List<String>> fields;

  @override
  String toString() => message;
}

/// Plain http is accepted only for a local development server.
String normalizeBaseUrl(String raw, {bool allowInsecureLocal = false}) {
  final url = raw.trim().replaceAll(RegExp(r'/+$'), '');
  Uri u;
  try {
    u = Uri.parse(url);
  } catch (_) {
    throw ApiError(0, 'bad_url', 'The server address is not valid.');
  }
  if (!u.hasScheme || u.host.isEmpty) throw ApiError(0, 'bad_url', 'The server address is not valid.');
  final local = ['localhost', '127.0.0.1', '10.0.2.2'].contains(u.host);
  if (u.scheme != 'https' && !(allowInsecureLocal && local && u.scheme == 'http')) {
    throw ApiError(0, 'insecure_url', 'The server address must start with https://');
  }
  if (u.userInfo.isNotEmpty || u.hasQuery || u.hasFragment) {
    throw ApiError(0, 'bad_url', 'Give only the domain in the address.');
  }
  final path = u.path == '/' ? '' : u.path;
  return '${u.scheme}://${u.host}${u.hasPort ? ':${u.port}' : ''}$path';
}

class ApiClient {
  ApiClient({
    required String baseUrl,
    required this.getToken,
    required this.getTenant,
    this.onUnauthorized,
    this.appVersion = '0',
    this.allowInsecureLocal = false,
    http.Client? client,
  })  : _base = '${normalizeBaseUrl(baseUrl, allowInsecureLocal: allowInsecureLocal)}/api/v1',
        _http = client ?? http.Client();

  final String _base;
  final http.Client _http;
  final String? Function() getToken;
  final String? Function() getTenant;
  final void Function()? onUnauthorized;
  final String appVersion;
  final bool allowInsecureLocal;

  /// Returns the `data` field of the answer (any JSON value).
  Future<dynamic> request(String method, String path,
      {Object? body, bool tenant = true, Duration timeout = const Duration(seconds: 20)}) async {
    final headers = <String, String>{'Accept': 'application/json', 'X-App-Version': appVersion};
    final token = getToken();
    if (token != null) headers['Authorization'] = 'Bearer $token';
    if (tenant) {
      final t = getTenant();
      if (t != null) headers['X-Tenant-Id'] = t;
    }
    if (body != null) headers['Content-Type'] = 'application/json';

    final uri = Uri.parse(_base + path);
    http.Response res;
    try {
      final enc = body == null ? null : jsonEncode(body);
      final Future<http.Response> f;
      switch (method) {
        case 'GET':
          f = _http.get(uri, headers: headers);
        case 'POST':
          f = _http.post(uri, headers: headers, body: enc);
        case 'PUT':
          f = _http.put(uri, headers: headers, body: enc);
        case 'DELETE':
          f = _http.delete(uri, headers: headers, body: enc);
        default:
          throw ArgumentError('method');
      }
      res = await f.timeout(timeout);
    } on TimeoutException {
      throw ApiError(0, 'timeout', 'The server is not responding. Try again shortly.');
    } catch (_) {
      throw ApiError(0, 'network', 'Could not connect to the internet or the server.');
    }

    Map<String, dynamic> json = {};
    try {
      final d = jsonDecode(utf8.decode(res.bodyBytes));
      if (d is Map<String, dynamic>) json = d;
    } catch (_) {/* non-JSON body (proxy error page) */}

    if (res.statusCode >= 200 && res.statusCode < 300) {
      if (!json.containsKey('data')) throw ApiError(res.statusCode, 'bad_response', 'The server’s reply could not be understood.');
      return json['data'];
    }
    if (res.statusCode == 401) {
      onUnauthorized?.call();
      throw ApiError(401, 'unauthenticated', 'Log in again.');
    }
    final code = json['code'] is String
        ? json['code'] as String
        : res.statusCode == 422
            ? 'validation'
            : res.statusCode == 429
                ? 'rate_limited'
                : res.statusCode >= 500
                    ? 'server_error'
                    : 'http_${res.statusCode}';
    final message = json['message'] is String && (json['message'] as String).isNotEmpty
        ? json['message'] as String
        : res.statusCode == 429
            ? 'Too many requests. Try again shortly.'
            : 'Something went wrong.';
    final fields = <String, List<String>>{};
    if (json['errors'] is Map) {
      (json['errors'] as Map).forEach((k, v) {
        if (v is List) fields['$k'] = v.map((e) => '$e').toList();
      });
    }
    throw ApiError(res.statusCode, code, message, fields);
  }
}

/// One method per server route, so the rest of the app never builds a URL by hand. Results are plain JSON maps/lists.
class Api {
  Api(this.c);
  final ApiClient c;

  Map<String, dynamic> _m(dynamic d) => (d as Map).cast<String, dynamic>();
  List<Map<String, dynamic>> _l(dynamic d) => (d as List).map((e) => (e as Map).cast<String, dynamic>()).toList();

  Future<Map<String, dynamic>> login(String email, String password, String deviceName, {String? code}) async =>
      _m(await c.request('POST', '/auth/login', body: {'email': email, 'password': password, 'device_name': deviceName, if (code != null && code.isNotEmpty) 'code': code}, tenant: false));
  Future<Map<String, dynamic>> pair(String code, String deviceName) async =>
      _m(await c.request('POST', '/auth/pair', body: {'code': code, 'device_name': deviceName}, tenant: false));
  Future<void> logout() => c.request('POST', '/auth/logout', tenant: false);
  Future<Map<String, dynamic>> bootstrap() async => _m(await c.request('GET', '/mobile/bootstrap'));

  Future<void> registerDevice(String name, String platform, String? pushToken, String appVersion) =>
      c.request('POST', '/mobile/device', body: {'name': name, 'platform': platform, 'push_token': pushToken, 'app_version': appVersion});
  Future<void> setPin(String pin, String password) => c.request('POST', '/mobile/pin', body: {'pin': pin, 'password': password});
  Future<void> stepUp(String pin) => c.request('POST', '/mobile/step-up', body: {'pin': pin});

  Future<Map<String, dynamic>> dashboard() async => _m(await c.request('GET', '/mobile/dashboard'));
  Future<Map<String, dynamic>> usage() async => _m(await c.request('GET', '/mobile/usage'));
  Future<Map<String, dynamic>> automations() async => _m(await c.request('GET', '/mobile/automations'));
  Future<Map<String, dynamic>> health() async => _m(await c.request('GET', '/mobile/health'));

  Future<List<Map<String, dynamic>>> approvals() async => _l(await c.request('GET', '/mobile/approvals'));
  Future<void> decide(String kind, String ref, String decision) =>
      c.request('POST', '/mobile/approvals/${Uri.encodeComponent(kind)}/${Uri.encodeComponent(ref)}', body: {'decision': decision});

  Future<List<Map<String, dynamic>>> pcDevices() async => _l(await c.request('GET', '/mobile/pc/devices'));
  Future<void> pcRequest(String deviceId, String kind, String target) =>
      c.request('POST', '/mobile/pc/request', body: {'device_id': deviceId, 'kind': kind, 'target': target});
  Future<List<Map<String, dynamic>>> pcActions() async => _l(await c.request('GET', '/mobile/pc/actions'));
  Future<void> decidePc(String id, String decision) =>
      c.request('POST', '/mobile/pc/actions/${Uri.encodeComponent(id)}', body: {'decision': decision});

  Future<Map<String, dynamic>> setEmergency(String action, {String? reason}) async =>
      _m(await c.request('POST', '/mobile/emergency', body: {'action': action, if (reason != null && reason.isNotEmpty) 'reason': reason}));

  Future<List<Map<String, dynamic>>> notifications({bool unreadOnly = false}) async =>
      _l(await c.request('GET', '/mobile/notifications${unreadOnly ? '?unread=1' : ''}'));
  Future<void> markAllRead() => c.request('POST', '/mobile/notifications/read', body: {'all': true});

  Future<List<Map<String, dynamic>>> conversations() async => _l(await c.request('GET', '/mobile/chat/conversations'));
  Future<Map<String, dynamic>> conversation(String id) async =>
      _m(await c.request('GET', '/mobile/chat/conversations/${Uri.encodeComponent(id)}'));
  Future<Map<String, dynamic>> sendChat(String text, String? conversationId) async => _m(await c.request('POST', '/mobile/chat/send',
      body: {'text': text, if (conversationId != null) 'conversation_id': conversationId}, timeout: const Duration(seconds: 150)));
}
