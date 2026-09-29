import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";

test("實際 Gateway 經 Horizon 生成草稿，人工確認後才公開", async ({
    browser,
}) => {
    test.skip(
        process.env.YACS_LIVE_BROWSER !== "1",
        "需明確啟用，會呼叫已配置的真實模型",
    );
    test.setTimeout(100000);
    const access = JSON.parse(
        readFileSync("storage/app/private/demo-access.json", "utf8"),
    );
    const staffContext = await browser.newContext();
    const visitorContext = await browser.newContext();
    const staff = await staffContext.newPage();
    const visitor = await visitorContext.newPage();
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
    const widget = visitor.frameLocator("iframe");
    const created = visitor.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/widget/conversations"),
    );
    await widget.getByLabel("您的訊息").fill("如何申請退款？");
    await widget.getByRole("button", { name: "送出" }).click();
    const conversationId = (await (await created).json()).data.id as string;
    await expect(
        widget.getByText("如何申請退款？", { exact: true }),
    ).toBeVisible();
    const row = staff
        .locator(".conversation-row")
        .filter({ hasText: conversationId.slice(-8) });
    await expect(row).toBeVisible({ timeout: 15000 });
    await row.click();
    await staff.getByRole("button", { name: "接手對話", exact: true }).click();
    await staff.getByRole("button", { name: "✧ AI 草稿", exact: true }).click();
    await expect(staff.locator(".ai-preview")).toBeVisible({ timeout: 85000 });
    const answer = await staff.locator(".ai-preview p").innerText();
    expect(answer.length).toBeGreaterThan(10);
    await expect(widget.getByText(answer, { exact: true })).toHaveCount(0);
    await staff.getByRole("button", { name: "放入草稿", exact: true }).click();
    await expect(staff.getByLabel("回覆內容")).toHaveValue(answer);
    await staff.getByRole("button", { name: "送出", exact: true }).click();
    await expect(widget.getByText(answer, { exact: true })).toBeVisible();
    await staff.getByRole("button", { name: "✓ 結案", exact: true }).click();
    await staffContext.close();
    await visitorContext.close();
});
