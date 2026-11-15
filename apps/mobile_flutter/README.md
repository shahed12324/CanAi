# CANAI mobile app (Flutter)

Same features and same server API (`/api/v1`) as the earlier Expo app in `../mobile`: log in or **scan the QR code** from the
website (sidebar → *Mobile app*), Home (credits, emergency stop), Chat, Approvals (PIN step-up), Monitor, My Computer, Security/PIN,
Notifications, Usage, Health, Workspaces.

## First run
```bash
cd apps/mobile_flutter
flutter create . --org com.canai --project-name canai --platforms android,ios   # generates android/ and ios/ (keeps lib/ and pubspec.yaml)
flutter pub get
flutter test                       # pure-Dart tests (QR parsing, PIN rule, formatting)
flutter run --dart-define=API_URL=https://your-domain.com
```

## Required platform settings (after `flutter create`)
* **Android** `android/app/src/main/AndroidManifest.xml`: add `<uses-permission android:name="android.permission.CAMERA"/>` and `<uses-permission android:name="android.permission.INTERNET"/>`. Set `minSdkVersion 23` in `android/app/build.gradle`.
* **iOS** `ios/Runner/Info.plist`: add `NSCameraUsageDescription` = "CANAI uses the camera only to scan the QR code that connects this phone to your account."

## Build
```bash
flutter build apk --release --dart-define=API_URL=https://your-domain.com      # Android APK
flutter build appbundle --release                                              # Play Store
flutter build ipa --release                                                    # iOS (needs a Mac + Apple developer account)
```
The app icon/name: change the label to "CANAI" in `AndroidManifest.xml` / `Info.plist`, icons via the `flutter_launcher_icons` package.

## Not in this first Flutter version (was in the Expo app)
* Push notifications (need a Firebase project + `firebase_messaging`; notifications are still in the in-app list).
* Fingerprint/Face confirmation of the PIN (add `local_auth`; the PIN is still verified by the server).
