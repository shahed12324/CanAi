import 'dart:io' show Platform;

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api_client.dart';

const appVersion = '1.0.0';
const defaultBase = String.fromEnvironment('API_URL', defaultValue: '');

enum SessionStatus { loading, out, signedIn }

/// Everything sensitive lives in the OS keychain/keystore (flutter_secure_storage), never in plain files.
class Session extends ChangeNotifier {
  final _store = const FlutterSecureStorage();
  SessionStatus status = SessionStatus.loading;
  String baseUrl = defaultBase;
  String? tenant;
  Map<String, dynamic>? boot;
  String? _token;
  Api _api = Api(ApiClient(baseUrl: 'https://invalid.invalid', getToken: () => null, getTenant: () => null));
  Api get api => _api;

  Map<String, dynamic>? get can => boot?['can'] is Map ? (boot!['can'] as Map).cast<String, dynamic>() : null;
  bool canDo(String k) => can?[k] == true;
  List<Map<String, dynamic>> get workspaces =>
      ((boot?['workspaces'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
  Map<String, dynamic> get user => (boot?['user'] as Map?)?.cast<String, dynamic>() ?? const {};

  Api _makeApi(String base) {
    final c = ApiClient(
      baseUrl: base,
      getToken: () => _token,
      getTenant: () => tenant,
      onUnauthorized: () => _forget(),
      appVersion: appVersion,
      allowInsecureLocal: kDebugMode,
    );
    return Api(c);
  }

  Future<String> _deviceName() async {
    try {
      final info = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final a = await info.androidInfo;
        return '${a.manufacturer} ${a.model}'.trim();
      }
      if (Platform.isIOS) return (await info.iosInfo).name;
    } catch (_) {}
    return 'phone';
  }

  Future<void> start() async {
    final t = await _store.read(key: 'token');
    final tn = await _store.read(key: 'tenant');
    final b = await _store.read(key: 'base') ?? defaultBase;
    baseUrl = b;
    if (t == null || b.isEmpty) {
      status = SessionStatus.out;
      notifyListeners();
      return;
    }
    _token = t;
    try {
      _api = _makeApi(b);
      await _enter(tn);
    } on ApiError catch (e) {
      if (e.code == 'unauthenticated') {
        await _forget();
      } else {
        status = SessionStatus.out; // offline at start: show login, token stays stored
        notifyListeners();
      }
    } catch (_) {
      status = SessionStatus.out;
      notifyListeners();
    }
  }

  Future<void> _enter(String? want) async {
    tenant = null;
    final first = await _api.bootstrap();
    final list = ((first['workspaces'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
    final chosen = list.cast<Map<String, dynamic>?>().firstWhere((w) => w!['id'] == want, orElse: () => list.isEmpty ? null : list.first);
    if (chosen == null) throw ApiError(0, 'no_workspace', 'You have no active workspace. Go to the website and create one.');
    tenant = chosen['id'] as String;
    final full = await _api.bootstrap(); // again with the workspace: now it also says what I may do there
    await _store.write(key: 'tenant', value: tenant);
    boot = full;
    status = SessionStatus.signedIn;
    notifyListeners();
    // A phone is registered per workspace (push is added later); never block entering the app.
    _deviceName().then((n) => _api.registerDevice(n, Platform.isIOS ? 'ios' : 'android', null, appVersion)).catchError((_) {});
  }

  Future<void> _finishLogin(String clean, Map<String, dynamic> r) async {
    _token = r['token'] as String;
    await _store.write(key: 'token', value: _token);
    await _store.write(key: 'base', value: clean);
    baseUrl = clean;
    try {
      await _enter(await _store.read(key: 'tenant'));
    } catch (e) {
      await _forget();
      rethrow;
    }
  }

  Future<void> signIn(String base, String email, String password, {String? code}) async {
    final clean = normalizeBaseUrl(base, allowInsecureLocal: kDebugMode);
    _api = _makeApi(clean);
    final r = await _api.login(email.trim(), password, await _deviceName(), code: code);
    await _finishLogin(clean, r);
  }

  /// QR pairing: the website made a one-time code for a signed-in user.
  Future<void> pairWith(String base, String code) async {
    final clean = normalizeBaseUrl(base, allowInsecureLocal: kDebugMode);
    _api = _makeApi(clean);
    final r = await _api.pair(code, await _deviceName());
    await _finishLogin(clean, r);
  }

  Future<void> signOut() async {
    try {
      await _api.logout();
    } catch (_) {/* offline: the token is still forgotten here */}
    await _forget();
  }

  Future<void> switchTenant(String id) async {
    tenant = id;
    await _store.write(key: 'tenant', value: id);
    boot = await _api.bootstrap();
    notifyListeners();
  }

  Future<void> _forget() async {
    _token = null;
    tenant = null;
    boot = null;
    await _store.delete(key: 'token');
    await _store.delete(key: 'tenant'); // keep the server address for next time
    status = SessionStatus.out;
    notifyListeners();
  }
}

class SessionScope extends InheritedNotifier<Session> {
  const SessionScope({super.key, required Session session, required super.child}) : super(notifier: session);
  static Session of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<SessionScope>()!.notifier!;
}
