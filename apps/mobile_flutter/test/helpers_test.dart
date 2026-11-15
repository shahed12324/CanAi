import 'package:canai/api_client.dart';
import 'package:canai/helpers.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final code = 'a1' * 20;

  test('credits groups digits and trims zeros', () {
    expect(credits('1234.50'), '1,234.5');
    expect(credits('0.0000'), '0');
    expect(credits(null), '0');
    expect(credits('-1000'), '-1,000');
  });

  test('versionAtLeast', () {
    expect(versionAtLeast('1.2.0', '1.1.9'), true);
    expect(versionAtLeast('1.0.0', '1.0.1'), false);
    expect(versionAtLeast('1.0', '1.0.0'), true);
  });

  test('pinProblem', () {
    expect(pinProblem('12'), isNotNull);
    expect(pinProblem('111111'), isNotNull);
    expect(pinProblem('123456'), isNotNull);
    expect(pinProblem('345678'), isNotNull);
    expect(pinProblem('492817'), isNull);
  });

  test('parsePairing accepts only a valid canai QR', () {
    final ok = parsePairing('canai://pair?server=${Uri.encodeComponent('https://app.example.com')}&code=$code');
    expect(ok?.server, 'https://app.example.com');
    expect(ok?.code, code);
    expect(parsePairing('https://evil.com/?code=$code'), isNull);
    expect(parsePairing('canai://pair?server=https%3A%2F%2Fa.com&code=short'), isNull);
    expect(parsePairing('canai://pair?server=http%3A%2F%2Fa.com&code=$code'), isNull);
    expect(parsePairing('canai://pair?server=${Uri.encodeComponent('https://u:p@a.com')}&code=$code'), isNull);
    expect(parsePairing(''), isNull);
  });

  test('normalizeBaseUrl', () {
    expect(normalizeBaseUrl('https://a.com/'), 'https://a.com');
    expect(normalizeBaseUrl('https://a.com:8443/x'), 'https://a.com:8443/x');
    expect(() => normalizeBaseUrl('http://a.com'), throwsA(isA<ApiError>()));
    expect(normalizeBaseUrl('http://localhost:8000', allowInsecureLocal: true), 'http://localhost:8000');
  });

  test('pcSummary and cleanTarget', () {
    expect(pcSummary({'params': {'path': '/a', 'content': 'x' * 30}}), ['Path: /a', 'Content size: 30 characters']);
    expect(pcSummary({'params': {'command': 'git', 'args': ['status', '-s']}}), ['Command: git', 'Arguments: status -s']);
    expect(cleanTarget('  '), isNull);
    expect(cleanTarget('a\nb'), isNull);
    expect(cleanTarget(' C:\\a.txt '), 'C:\\a.txt');
  });
}
