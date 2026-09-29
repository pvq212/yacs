import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
const access = JSON.parse(
    readFileSync("storage/app/private/demo-access.json", "utf8"),
);
test("會員聊天、客服接手、內部備註隔離、回覆與結案", async ({ browser }) => {
    const staffContext = await browser.newContext(),
        visitorContext = await browser.newContext();
    const staff = await staffContext.newPage(),
        visitor = await visitorContext.newPage();
    let subscribed = false;
    visitor.on("websocket", (socket) =>
        socket.on("framereceived", (frame) => {
            try {
                if (
                    JSON.parse(String(frame.payload)).event ===
                    "pusher_internal:subscription_succeeded"
                )
                    subscribed = true;
            } catch {}
        }),
    );
    const errors: string[] = [];
    staff.on("pageerror", (e) => errors.push(e.message));
    visitor.on("pageerror", (e) => errors.push(e.message));
    await staff.goto("/agent");
    await staff
        .getByLabel("電子郵件", { exact: true })
        .fill(access.owner.email);
    await staff.getByLabel("密碼", { exact: true }).fill(access.owner.password);
    await staff.getByRole("button", { name: "登入", exact: true }).click();
    await expect(
        staff.getByRole("heading", { name: "對話收件匣", exact: true }),
    ).toBeVisible();
    await visitor.goto("/demo");
    await visitor.getByRole("button", { name: "聯絡客服" }).click();
    const widget = visitor.frameLocator('iframe[title="聯絡客服"]');
    const question = `瀏覽器測試退款-${Date.now()}`;
    const created = visitor.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/widget/conversations"),
    );
    await widget.getByLabel("您的訊息").fill(question);
    await widget.getByRole("button", { name: "送出" }).click();
    const conversationId = (await (await created).json()).data.id as string;
    await expect(widget.getByText(question, { exact: true })).toBeVisible();
    await expect.poll(() => subscribed).toBe(true);
    await widget.locator("input[type=file]").setInputFiles({
        name: "測試附件.txt",
        mimeType: "text/plain",
        buffer: Buffer.from("附件測試內容"),
    });
    await expect(
        widget.getByRole("button", { name: "測試附件.txt ×" }),
    ).toBeVisible({ timeout: 20000 });
    await widget.getByRole("button", { name: "送出" }).click();
    await expect(
        widget.getByRole("button", { name: "↧ 測試附件.txt" }),
    ).toBeVisible();
    const row = staff
        .locator(".conversation-row")
        .filter({ hasText: conversationId.slice(-8) });
    await expect(row).toBeVisible({ timeout: 15000 });
    await row.click();
    await staff.getByRole("button", { name: "接手對話", exact: true }).click();
    await staff.getByRole("button", { name: "內部備註", exact: true }).click();
    await staff
        .getByLabel("內部備註", { exact: true })
        .fill("測試機密內部備註");
    await staff.getByRole("button", { name: "送出", exact: true }).click();
    await expect(
        staff.getByText("測試機密內部備註", { exact: true }),
    ).toBeVisible();
    await staff.getByRole("button", { name: "公開回覆", exact: true }).click();
    await staff.getByLabel("回覆內容").fill("請到會員中心申請退款，謝謝。");
    await staff.getByRole("button", { name: "送出", exact: true }).click();
    await expect(
        widget.getByText("請到會員中心申請退款，謝謝。", { exact: true }),
    ).toBeVisible();
    await expect(
        widget.getByText("測試機密內部備註", { exact: true }),
    ).toHaveCount(0);
    await staff.getByRole("button", { name: "✓ 結案", exact: true }).click();
    await expect(widget.getByText("這次服務有幫助嗎？")).toBeVisible();
    await widget.getByRole("button", { name: "5 分", exact: true }).click();
    await expect(widget.getByText("謝謝您的回饋！")).toBeVisible();
    await visitor.reload();
    await visitor.getByRole("button", { name: "聯絡客服" }).click();
    await expect(
        widget.getByText("請到會員中心申請退款，謝謝。", { exact: true }),
    ).toBeVisible();
    expect(errors).toEqual([]);
    await staff.screenshot({
        path: "tests/.artifacts/staff-desktop.png",
        fullPage: true,
    });
    await visitor.screenshot({
        path: "tests/.artifacts/widget-desktop.png",
        fullPage: true,
    });
    await staffContext.close();
    await visitorContext.close();
});
test("訪客 FAQ 與手機介面", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/demo");
    await page.getByRole("button", { name: "聯絡客服" }).click();
    const widget = page.frameLocator("iframe");
    await widget.getByRole("button", { name: "常見問題", exact: true }).click();
    await widget.getByText("如何申請退款？", { exact: true }).click();
    await expect(widget.getByText(/收到商品後七日內/)).toBeVisible();
    await page.screenshot({
        path: "tests/.artifacts/widget-mobile.png",
        fullPage: true,
    });
});

test("SDK destroy 撤銷訪客 session 並移除 iframe", async ({
    page,
    context,
}) => {
    await page.goto("/demo");
    const boot = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/bootstrap"),
    );
    await page.getByRole("button", { name: "聯絡客服" }).click();
    const session = (await (await boot).json()).data;
    await expect(
        page.frameLocator("iframe").getByLabel("您的訊息"),
    ).toBeVisible();
    await page.evaluate(async () => {
        await (
            window as unknown as { Yacs: { destroy: () => Promise<void> } }
        ).Yacs.destroy();
    });
    await expect(page.locator('iframe[title="聯絡客服"]')).toHaveCount(0);
    const response = await context.request.get("/api/v1/widget/conversations", {
        headers: { Authorization: `Bearer ${session.access_token}` },
    });
    expect(response.status()).toBe(401);
});
