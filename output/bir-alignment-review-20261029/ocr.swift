import Foundation
import Vision
for path in CommandLine.arguments.dropFirst() {
 print("\nPAGE: \(URL(fileURLWithPath:path).lastPathComponent)\n")
 let request = VNRecognizeTextRequest()
 request.recognitionLevel = .accurate
 request.usesLanguageCorrection = false
 request.recognitionLanguages = ["en-US"]
 try VNImageRequestHandler(url: URL(fileURLWithPath:path), options: [:]).perform([request])
 for result in request.results ?? [] { if let value = result.topCandidates(1).first { print(value.string) } }
}
