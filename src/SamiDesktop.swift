import AVFoundation
import AppKit

final class SamiWindow: NSWindow {
    override var canBecomeKey: Bool { true }

    override func mouseDown(with event: NSEvent) {
        let start = NSEvent.mouseLocation
        let origin = frame.origin

        while let next = nextEvent(matching: [.leftMouseDragged, .leftMouseUp]) {
            if next.type == .leftMouseUp {
                break
            }

            let now = NSEvent.mouseLocation

            let under = NSScreen.screens.first { NSMouseInRect(now, $0.frame, false) }
                ?? screen

            setFrame(
                NSRect(
                    x: origin.x + (now.x - start.x),
                    y: under?.frame.minY ?? frame.minY,
                    width: frame.width,
                    height: frame.height
                ),
                display: true
            )
        }
    }
}

final class SamiView: NSView {
    override func mouseDown(with event: NSEvent) {
        window?.mouseDown(with: event)
    }
}

final class Delegate: NSObject, NSApplicationDelegate {
    private var window: SamiWindow!
    private var players: [AVQueuePlayer] = []
    private var layers: [AVPlayerLayer] = []
    private var loopers: [AVPlayerLooper?] = [nil, nil]
    private var front = 0

    /// A one-shot clip that arrived mid-loop, waiting for the loop to come round.
    private var queued: URL?
    private var waitingOnLoop: Any?
    private let source: URL
    private let side: CGFloat
    private let loops: Bool

    private func leave() -> Never {
        exit(0)
    }

    init(source: URL, side: CGFloat, loops: Bool) {
        self.source = source
        self.side = side
        self.loops = loops
    }

    private func standingPlace() -> NSRect {
        let screen = standingOn
            ?? NSScreen.screens.first { NSMouseInRect(NSEvent.mouseLocation, $0.frame, false) }
            ?? NSScreen.main
            ?? NSScreen.screens.first

        let full = screen?.frame ?? .zero

        return NSRect(
            x: full.midX - side / 2,
            y: full.minY,
            width: side,
            height: side
        )
    }

    private var standingOn: NSScreen?

    private var homeWindow: CGWindowID?

    private func ownerWindows() -> [(id: CGWindowID, frame: NSRect)] {
        guard let pid = owner?.processIdentifier else { return [] }

        let listed = CGWindowListCopyWindowInfo([.optionOnScreenOnly, .excludeDesktopElements], kCGNullWindowID)

        guard let windows = listed as? [[String: Any]] else { return [] }

        return windows.filter {
            ($0[kCGWindowOwnerPID as String] as? pid_t) == pid
        }.compactMap { window in
            guard
                let id = window[kCGWindowNumber as String] as? CGWindowID,
                let bounds = window[kCGWindowBounds as String] as? [String: CGFloat]
            else {
                return nil
            }

            let frame = NSRect(
                x: bounds["X"] ?? 0,
                y: bounds["Y"] ?? 0,
                width: bounds["Width"] ?? 0,
                height: bounds["Height"] ?? 0
            )

            return frame.width >= 400 && frame.height >= 300 ? (id, frame) : nil
        }
    }

    private func screenShowingHome() -> NSScreen? {
        let windows = ownerWindows()

        if homeWindow == nil {
            homeWindow = windows.first?.id
        }

        guard let home = windows.first(where: { $0.id == homeWindow }) else {
            return nil
        }

        let flipped = NSPoint(
            x: home.frame.midX,
            y: (NSScreen.screens.first?.frame.maxY ?? 0) - home.frame.midY
        )

        return NSScreen.screens.first { NSMouseInRect(flipped, $0.frame, false) }
    }

    private func followHerWindow() {
        Timer.scheduledTimer(withTimeInterval: 1.0, repeats: true) { [weak self] _ in
            guard let self, let window = self.window else { return }

            guard let now = self.screenShowingHome(), now !== self.standingOn else { return }

            self.standingOn = now
            window.setFrame(self.standingPlace(), display: true, animate: false)
        }
    }

    private var owner: NSRunningApplication?

    private func terminalThatStartedMe() -> NSRunningApplication? {
        var pid = getppid()

        for _ in 0 ..< 6 {
            if let app = NSRunningApplication(processIdentifier: pid), app.bundleIdentifier != nil {
                return app
            }

            var info = kinfo_proc()
            var size = MemoryLayout<kinfo_proc>.stride
            var mib: [Int32] = [CTL_KERN, KERN_PROC, KERN_PROC_PID, pid]

            guard sysctl(&mib, 4, &info, &size, nil, 0) == 0, info.kp_eproc.e_ppid > 1 else {
                break
            }

            pid = info.kp_eproc.e_ppid
        }

        return NSWorkspace.shared.runningApplications.first { $0.isActive && $0.bundleIdentifier != nil }
    }

    private var watchingFocus = false

    private func hideWhenTheyLookAway() {
        guard !watchingFocus else { return }

        watchingFocus = true

        owner = owner ?? terminalThatStartedMe()

        NSWorkspace.shared.notificationCenter.addObserver(
            forName: NSWorkspace.didActivateApplicationNotification,
            object: nil,
            queue: .main
        ) { [weak self] note in
            guard let self, let owner = self.owner else { return }

            let now = note.userInfo?[NSWorkspace.applicationUserInfoKey] as? NSRunningApplication

            let mine = now?.processIdentifier == ProcessInfo.processInfo.processIdentifier

            let theirs = mine || now?.bundleIdentifier == owner.bundleIdentifier

            if theirs {
                self.window?.orderFrontRegardless()
            } else {
                self.window?.orderOut(nil)
            }
        }
    }

    private var restingClip: URL?

    private var loaded: [String: AVPlayerItem] = [:]

    private func warmTheRest(besides first: URL) {
        let folder = first.deletingLastPathComponent()

        guard let names = try? FileManager.default.contentsOfDirectory(atPath: folder.path) else {
            return
        }

        for name in names where name.hasSuffix(".mov") {
            preload(folder.appendingPathComponent(name))
        }
    }

    private func preload(_ url: URL) {
        let key = url.path

        guard loaded[key] == nil else { return }

        let asset = AVURLAsset(url: url)
        let item = AVPlayerItem(asset: asset)

        asset.loadValuesAsynchronously(forKeys: ["tracks", "duration"])

        loaded[key] = item
    }

    private func play(_ url: URL, looping: Bool) {
        preload(url)

        let item = AVPlayerItem(asset: (loaded[url.path]?.asset as? AVURLAsset) ?? AVURLAsset(url: url))

        let back = 1 - front
        let player = players[back]

        loopers[back] = nil
        player.removeAllItems()

        if looping {
            restingClip = url
            loopers[back] = AVPlayerLooper(player: player, templateItem: item)
        } else {
            player.insert(item, after: nil)

            NotificationCenter.default.addObserver(
                forName: .AVPlayerItemDidPlayToEndTime,
                object: item,
                queue: .main
            ) { [weak self] _ in
                FileHandle.standardOutput.write("finished\n".data(using: .utf8)!)

                guard let self, let resting = self.restingClip else { return }

                self.play(resting, looping: true)
            }
        }

        player.play()

        bringForward(back)
    }

    private func bringForward(_ next: Int) {
        layers[next].isHidden = false

        let previous = front
        front = next

        guard previous != next else { return }

        DispatchQueue.main.asyncAfter(deadline: .now() + 0.08) { [weak self] in
            guard let self, self.front != previous else { return }

            self.layers[previous].isHidden = true
            self.players[previous].pause()
        }
    }

    private var heardSoFar = ""

    private func heard(_ byte: UInt8) {
        guard byte != 0x0A else {
            let line = heardSoFar
            heardSoFar = ""

            DispatchQueue.main.async { self.wasToldToPlay(line) }

            return
        }

        heardSoFar.append(Character(UnicodeScalar(byte)))
    }

    /// Play a one-shot clip once the loop reaches its end, rather than cutting in.
    ///
    /// She is typing, or lifting a hand, or halfway through a glance — swapping
    /// on the instant a beat arrives snaps her out of it. The loop returns to
    /// the frame it opened on, which is the frame every spoken clip opens on
    /// too, so waiting for it is what makes the two join without a jump.
    private func playAtTheEndOfTheLoop(_ url: URL) {
        guard let item = players[front].currentItem, loopers[front] != nil else {
            play(url, looping: false)

            return
        }

        queued = url

        if let waiting = waitingOnLoop {
            NotificationCenter.default.removeObserver(waiting)
        }

        waitingOnLoop = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemDidPlayToEndTime,
            object: item,
            queue: .main
        ) { [weak self] _ in
            guard let self, let next = self.queued else { return }

            self.queued = nil

            if let waiting = self.waitingOnLoop {
                NotificationCenter.default.removeObserver(waiting)
                self.waitingOnLoop = nil
            }

            self.play(next, looping: false)
        }
    }

    private func wasToldToPlay(_ line: String) {
        let wantsLoop = line.hasSuffix(" --loop")
        let path = wantsLoop ? String(line.dropLast(7)) : line

        guard FileManager.default.fileExists(atPath: path) else { return }

        let url = URL(fileURLWithPath: path)

        if wantsLoop {
            queued = nil

            play(url, looping: true)

            return
        }

        playAtTheEndOfTheLoop(url)
    }

    private func listenForClips() {
        let input = FileHandle.standardInput
        DispatchQueue.global(qos: .utility).async {
            var byte: UInt8 = 0

            while true {
                let got = read(input.fileDescriptor, &byte, 1)

                if got == 0 { DispatchQueue.main.async { exit(0) } ; return }
                if got < 0 && errno != EINTR { DispatchQueue.main.async { exit(0) } ; return }

                if got == 1 {
                    self.heard(byte)
                }
            }
        }

    }

    func applicationDidFinishLaunching(_: Notification) {

        owner = terminalThatStartedMe()

        standingOn = screenShowingHome()

        let frame = standingPlace()

        window = SamiWindow(
            contentRect: frame,
            styleMask: [.borderless],
            backing: .buffered,
            defer: false
        )

        window.isOpaque = false
        window.backgroundColor = .clear
        window.hasShadow = false
        window.level = .floating
        window.ignoresMouseEvents = false
        window.isMovableByWindowBackground = false

        window.collectionBehavior = [.moveToActiveSpace, .stationary]

        let bounds = NSRect(origin: .zero, size: frame.size)

        let host = SamiView(frame: bounds)
        host.wantsLayer = true
        host.layer = CALayer()
        host.layer?.backgroundColor = NSColor.clear.cgColor

        for _ in 0 ..< 2 {
            let player = AVQueuePlayer()
            let layer = AVPlayerLayer(player: player)

            layer.frame = bounds
            layer.videoGravity = .resizeAspect
            layer.backgroundColor = NSColor.clear.cgColor
            layer.isHidden = true

            layer.pixelBufferAttributes = [
                kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            ]

            players.append(player)
            layers.append(layer)
            host.layer?.addSublayer(layer)
        }

        window.contentView = host

        window.orderFrontRegardless()
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)

        if source.lastPathComponent != "-" {
            play(source, looping: loops)
        }

        hideWhenTheyLookAway()
        followHerWindow()
        listenForClips()
        warmTheRest(besides: source)
    }
}

let args = CommandLine.arguments

guard args.count > 1 else {
    print("usage: SamiDesktop <file.webm|mov> [size] [--loop]")
    exit(1)
}

let app = NSApplication.shared
app.setActivationPolicy(.accessory)

let delegate = Delegate(
    source: URL(fileURLWithPath: args[1]),
    side: args.count > 2 ? CGFloat(Double(args[2]) ?? 360) : 360,
    loops: args.contains("--loop")
)

app.delegate = delegate
app.run()
